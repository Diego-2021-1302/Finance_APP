<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\CreditCardFeeRule;
use App\Models\DebtBalanceMovement;
use Illuminate\Validation\ValidationException;

class CreditCardAuthorizationEngine
{
    /**
     * Calculate credit capacity metrics for a given Debt or Card.
     */
    public function getCapacityMetrics(Debt $debt): array
    {
        $approvedLimit = (string) ($debt->approved_credit_limit ?? $debt->credit_limit ?? '0.00');
        $overdraftLimit = (string) ($debt->authorized_overdraft_limit ?? '0.00');
        $usedBalance = (string) $debt->outstanding_amount;
        $limitStatus = $debt->limit_status ?? 'unknown';

        // Total authorized capacity = approved_limit + authorized_overdraft_limit
        $totalCapacity = bcadd($approvedLimit, $overdraftLimit, 2);

        // Remaining approved credit = max(0, approved_limit - used_balance)
        $remainingApproved = bccomp($approvedLimit, $usedBalance, 2) > 0
            ? bcsub($approvedLimit, $usedBalance, 2)
            : '0.00';

        // Used overdraft = max(0, min(authorized_overdraft_limit, used_balance - approved_limit))
        $overdraftPortion = bccomp($usedBalance, $approvedLimit, 2) > 0
            ? bcsub($usedBalance, $approvedLimit, 2)
            : '0.00';

        $usedOverdraft = bccomp($overdraftPortion, $overdraftLimit, 2) > 0
            ? $overdraftLimit
            : $overdraftPortion;

        // Remaining overdraft = max(0, authorized_overdraft_limit - used_overdraft)
        $remainingOverdraft = bcsub($overdraftLimit, $usedOverdraft, 2);
        if (bccomp($remainingOverdraft, '0.00', 2) < 0) {
            $remainingOverdraft = '0.00';
        }

        // Remaining total capacity = max(0, totalCapacity - used_balance)
        $remainingTotalCapacity = bccomp($totalCapacity, $usedBalance, 2) > 0
            ? bcsub($totalCapacity, $usedBalance, 2)
            : '0.00';

        return [
            'approved_credit_limit' => $approvedLimit,
            'authorized_overdraft_limit' => $overdraftLimit,
            'total_authorized_capacity' => $totalCapacity,
            'used_balance' => $usedBalance,
            'remaining_approved_credit' => $remainingApproved,
            'used_overdraft' => $usedOverdraft,
            'remaining_overdraft' => $remainingOverdraft,
            'remaining_total_capacity' => $remainingTotalCapacity,
            'limit_status' => $limitStatus,
        ];
    }

    /**
     * Evaluate whether a transaction amount can be authorized on the card,
     * calculate overdraft fee if it enters/deepens overdraft, and check if
     * (amount + fee) fits within remaining total capacity.
     */
    public function authorizeTransaction(Debt $debt, string $amount, ?string $eventIdempotencyKey = null): array
    {
        $metrics = $this->getCapacityMetrics($debt);
        $approvedLimit = $metrics['approved_credit_limit'];
        $overdraftLimit = $metrics['authorized_overdraft_limit'];
        $usedBalance = $metrics['used_balance'];
        $totalCapacity = $metrics['total_authorized_capacity'];
        $remainingCapacity = $metrics['remaining_total_capacity'];

        $limitStatus = $debt->limit_status;

        if ($limitStatus !== null) {
            if (in_array($limitStatus, ['unknown', 'pending_verification'], true)) {
                throw ValidationException::withMessages([
                    'account_id' => [
                        sprintf(
                            'La tarjeta "%s" tiene un estado de límite %s. Debe configurar el límite aprobado y el sobregiro autorizado antes de autorizar consumos.',
                            $debt->name,
                            $limitStatus === 'pending_verification' ? 'pendiente de verificación' : 'desconocido',
                        ),
                    ],
                ]);
            }

            if ($limitStatus === 'special_rules') {
                $documented = (bool) $debt->special_rules_documented;
                $source = trim((string) ($debt->special_rules_source ?? ''));
                $reference = trim((string) ($debt->special_rules_reference ?? ''));
                if (!$documented || $source === '' || $reference === '') {
                    throw ValidationException::withMessages([
                        'account_id' => [
                            sprintf('La tarjeta "%s" tiene reglas especiales sin documentación verificable. Debe vincular fuente y referencia contractual antes de autorizar consumos.', $debt->name),
                        ],
                    ]);
                }
            }

            $isExplicitZeroLimit = ($debt->limit_status === 'confirmed' && ($debt->approved_credit_limit !== null || $debt->authorized_overdraft_limit !== null));
            if ($isExplicitZeroLimit && bccomp($approvedLimit, '0.00', 2) === 0 && bccomp($overdraftLimit, '0.00', 2) === 0) {
                throw ValidationException::withMessages([
                    'account_id' => [
                        sprintf('La tarjeta "%s" tiene límite aprobado y sobregiro en cero confirmados. No tiene capacidad disponible para nuevas operaciones.', $debt->name),
                    ],
                ]);
            }
        }

        // New prospective balance before fee = usedBalance + amount
        $prospectiveBalance = bcadd($usedBalance, $amount, 2);

        // Check if transaction enters or uses overdraft
        $usesOverdraft = bccomp($prospectiveBalance, $approvedLimit, 2) > 0;

        $feeResult = $this->calculateOverdraftFee(
            $debt,
            $amount,
            $usedBalance,
            $prospectiveBalance,
            $approvedLimit,
            $usesOverdraft,
            $eventIdempotencyKey,
        );
        $fee = $feeResult['fee'];
        $tax = $feeResult['tax'];

        $totalFeeWithTax = bcadd($fee, $tax, 2);
        $totalCharge = bcadd($amount, $totalFeeWithTax, 2);

        // Check if total charge exceeds remaining total capacity
        if (bccomp($totalCharge, $remainingCapacity, 2) > 0) {
            $formattedLimit = $this->formatDecimal($totalCapacity);
            $formattedAvailable = $this->formatDecimal($remainingCapacity);
            $formattedCharge = $this->formatDecimal($totalCharge);

            throw ValidationException::withMessages([
                'account_id' => [
                    sprintf(
                        'La tarjeta "%s" excede su capacidad total autorizada. La operación por RD$ %s supera la capacidad disponible de RD$ %s (Límite total autorizado: RD$ %s).',
                        $debt->name,
                        $formattedCharge,
                        $formattedAvailable,
                        $formattedLimit
                    ),
                ],
            ]);
        }

        return [
            'authorized' => true,
            'uses_overdraft' => $usesOverdraft,
            'fee' => $fee,
            'tax' => $tax,
            'total_fee' => $totalFeeWithTax,
            'total_charge' => $totalCharge,
            'metrics' => $metrics,
            'fee_rule' => $feeResult['rule'],
        ];
    }

    private function calculateOverdraftFee(
        Debt $debt,
        string $amount,
        string $usedBalance,
        string $prospectiveBalance,
        string $approvedLimit,
        bool $usesOverdraft,
        ?string $eventIdempotencyKey,
    ): array {
        if (!$usesOverdraft) {
            return ['fee' => '0.00', 'tax' => '0.00', 'rule' => null];
        }

        $overdraftPortion = bcsub($prospectiveBalance, $approvedLimit, 2);
        $rules = CreditCardFeeRule::query()
            ->where('is_active', true)
            ->where('verification_status', 'verified')
            ->whereDate('effective_from', '<=', now()->toDateString())
            ->where(function ($query) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', now()->toDateString());
            })
            ->where(function ($query) use ($debt) {
                $query->whereNull('financial_institution_id');
                if ($debt->financial_institution_id) {
                    $query->orWhere('financial_institution_id', $debt->financial_institution_id);
                }
            })
            ->where(function ($query) use ($debt) {
                $query->whereNull('financial_product_variant_id');
                if ($debt->financial_product_variant_id) {
                    $query->orWhere('financial_product_variant_id', $debt->financial_product_variant_id);
                }
            })
            ->orderByDesc('effective_from')
            ->get();

        if ($rules->isNotEmpty()) {
            foreach ($rules as $rule) {
                $fee = $this->evaluateFeeRule($debt, $rule, $amount, $usedBalance, $approvedLimit, $overdraftPortion, $eventIdempotencyKey);
                if (bccomp($fee, '0.00', 2) > 0) {
                    $tax = '0.00';
                    if (bccomp((string) $rule->tax_rate, '0.00', 2) > 0) {
                        $tax = bcmul($fee, bcdiv((string) $rule->tax_rate, '100', 6), 2);
                    }
                    return [
                        'fee' => $fee,
                        'tax' => $tax,
                        'rule' => [
                            'id' => $rule->id,
                            'trigger' => $rule->trigger,
                            'formula_type' => $rule->formula_type,
                            'source_url' => $rule->source_url,
                            'verification_status' => $rule->verification_status,
                        ],
                    ];
                }
            }
        }

        // Compatibility fallback: explicit individual contract fields entered by the owner.
        if ($debt->overdraft_fee_type !== 'none') {
            $entersOverdraft = bccomp($usedBalance, $approvedLimit, 2) <= 0;
            if (!$entersOverdraft) {
                return ['fee' => '0.00', 'tax' => '0.00', 'rule' => null];
            }

            $fee = '0.00';
            if ($debt->overdraft_fee_type === 'fixed') {
                $fee = (string) $debt->overdraft_fee_amount;
            } elseif ($debt->overdraft_fee_type === 'percentage') {
                $pct = bcdiv((string) $debt->overdraft_fee_percentage, '100', 6);
                $fee = bcmul($overdraftPortion, $pct, 2);
            }

            $tax = '0.00';
            if (bccomp((string) $debt->overdraft_fee_tax_rate, '0.00', 2) > 0) {
                $tax = bcmul($fee, bcdiv((string) $debt->overdraft_fee_tax_rate, '100', 6), 2);
            }

            return [
                'fee' => $fee,
                'tax' => $tax,
                'rule' => [
                    'id' => 'individual-contract',
                    'trigger' => 'overdraft_entry',
                    'formula_type' => $debt->overdraft_fee_type,
                    'source_url' => null,
                    'verification_status' => 'individual_contract',
                ],
            ];
        }

        return ['fee' => '0.00', 'tax' => '0.00', 'rule' => null];
    }

    private function evaluateFeeRule(
        Debt $debt,
        CreditCardFeeRule $rule,
        string $amount,
        string $usedBalance,
        string $approvedLimit,
        string $overdraftPortion,
        ?string $eventIdempotencyKey,
    ): string {
        $exemptions = $rule->exemptions ?? [];
        if (isset($exemptions['max_amount']) && bccomp($amount, (string) $exemptions['max_amount'], 2) <= 0) {
            return '0.00';
        }

        $triggerApplies = match ($rule->trigger) {
            'overdraft_entry' => bccomp($usedBalance, $approvedLimit, 2) <= 0,
            'transaction' => true,
            'threshold' => $rule->threshold_amount !== null
                && bccomp($overdraftPortion, (string) $rule->threshold_amount, 2) >= 0,
            'period' => true,
            default => false,
        };
        if (!$triggerApplies) {
            return '0.00';
        }

        $marker = '[fee-rule:' . $rule->id . ']';
        $existing = DebtBalanceMovement::query()
            ->where('debt_id', $debt->id)
            ->where('kind', 'overdraft_fee')
            ->where('description', 'like', '%' . $marker . '%');

        if ($rule->trigger === 'overdraft_entry' || $rule->trigger === 'threshold') {
            if ($existing->exists()) {
                return '0.00';
            }
        }
        if ($rule->trigger === 'period') {
            $frequency = $rule->frequency ?? 'monthly';
            $periodQuery = DebtBalanceMovement::query()
                ->where('debt_id', $debt->id)
                ->where('kind', 'overdraft_fee')
                ->where('description', 'like', '%' . $marker . '%');
            if ($frequency === 'monthly') {
                $periodQuery->whereYear('occurred_at', now()->year)->whereMonth('occurred_at', now()->month);
            } elseif ($frequency === 'yearly') {
                $periodQuery->whereYear('occurred_at', now()->year);
            }
            if ($periodQuery->exists()) {
                return '0.00';
            }
        }
        if ($eventIdempotencyKey) {
            $feeKey = $this->feeEventIdempotencyKey($eventIdempotencyKey, $rule->id);
            if (DebtBalanceMovement::query()->where('debt_id', $debt->id)->where('idempotency_key', $feeKey)->exists()) {
                return '0.00';
            }
        }

        $fee = '0.00';
        if ($rule->formula_type === 'fixed') {
            $fee = (string) $rule->formula_value;
        } elseif ($rule->formula_type === 'percentage') {
            $pct = bcdiv((string) $rule->formula_value, '100', 6);
            $fee = bcmul($overdraftPortion, $pct, 2);
        }
        if ($rule->maximum_amount !== null && bccomp($fee, (string) $rule->maximum_amount, 2) > 0) {
            $fee = (string) $rule->maximum_amount;
        }
        return $fee;
    }

    private function formatDecimal(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '00');
        $whole = ltrim($whole, '0') ?: '0';
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;
        return ($negative ? '-' : '') . $whole . '.' . str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public function feeEventIdempotencyKey(string $eventIdempotencyKey, int|string $ruleId): string
    {
        $hex = hash('sha256', 'overdraft_fee:' . $eventIdempotencyKey . ':' . $ruleId);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
