<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use App\Models\Commitment;
use App\Models\CreditCardStatement;
use App\Models\Payment;
use App\Models\UserPreference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class InterestCutService
{
    public function __construct(private readonly FinancialProductEngine $products) {}

    public function calculate(int $userId): array
    {
        $now = now();
        $today = $now->copy()->startOfDay();
        $preference = UserPreference::query()->where('user_id', $userId)->first();
        $frequency = $preference?->pay_frequency ?? 'monthly';
        [$start, $end, $periodLabel] = $this->period($today, $frequency, $preference?->week_starts ?? 'Monday');
        $through = $now->copy()->min($end->copy()->endOfDay());
        $daysElapsed = $start->diffInDays($through->copy()->startOfDay()) + 1;

        $debts = Debt::query()
            ->where('user_id', $userId)
            ->whereIn('kind', ['credit_card', 'loan'])
            ->where('outstanding_amount', '>', 0)
            ->with('commitment')
            ->orderBy('kind')
            ->orderBy('name')
            ->get();
        $representedCommitments = $debts->pluck('commitment_id')->filter()->map(fn ($id) => (int) $id)->all();
        $representedNames = $debts->map(fn (Debt $debt) => $debt->kind . ':' . mb_strtolower($debt->name))->all();
        $loanCommitments = Commitment::query()
            ->where('user_id', $userId)
            ->whereIn('type', ['credit_card', 'loan'])
            ->where('current_balance', '>', 0)
            ->whereNotIn('id', $representedCommitments ?: [0])
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        $movementsByDebt = DebtBalanceMovement::query()
            ->where('user_id', $userId)
            ->whereIn('debt_id', $debts->pluck('id'))
            ->where('occurred_at', '<=', $through)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->groupBy('debt_id');

        $entries = $debts->map(fn (Debt $debt) => [
            'id' => 'debt-' . $debt->id,
            'debt' => $debt,
            'commitment' => $debt->commitment,
            'kind' => $debt->kind,
            'name' => $debt->name,
            'balance' => (string) $debt->outstanding_amount,
        ]);
        foreach ($loanCommitments as $commitment) {
            if (in_array($commitment->type . ':' . mb_strtolower($commitment->name), $representedNames, true)) {
                continue;
            }
            $entries->push([
                'id' => 'commitment-' . $commitment->id,
                'debt' => null,
                'commitment' => $commitment,
                'kind' => $commitment->type,
                'name' => $commitment->name,
                'balance' => (string) $commitment->current_balance,
            ]);
        }

        $items = [];
        $cardFinancingInterest = '0.00';
        $loanInterest = '0.00';
        $overdraftCharge = '0.00';

        foreach ($entries as $entry) {
            $debt = $entry['debt'];
            $commitment = $entry['commitment'];
            $kind = $entry['kind'];
            $balance = $entry['balance'];
            $movements = $debt ? $movementsByDebt->get($debt->id, collect()) : collect();
            $balanceEvents = $movements;
            if ($commitment && $kind === 'loan') {
                $payments = Payment::query()
                    ->where('user_id', $userId)
                    ->whereNull('reversed_at')
                    ->whereBetween('paid_at', [$start, $through])
                    ->whereHas('occurrence', fn ($query) => $query->where('commitment_id', $commitment->id))
                    ->with('occurrence')
                    ->get();
                foreach ($payments as $payment) {
                    $balanceEvents->push((object) [
                        'occurred_at' => $payment->paid_at,
                        'delta' => bcsub('0.00', (string) $payment->amount, 2),
                    ]);
                }
            }
            $balanceEvents = $balanceEvents->sortBy(fn ($movement) => $movement->occurred_at->getTimestamp())->values();
            $balances = $this->dailyBalances($balance, $balanceEvents, $start, $through);
            $product = $commitment ?? $debt;
            $conditions = $this->products->resolveApplicableConditions($product, $through->toDateString());
            $annualRate = (string) ($conditions['annual_interest_rate'] ?? '0.000');
            $rateMissing = $product->interest_rate === null && !($conditions['is_promotional'] ?? false);
            $dailyRate = bcdiv(bcdiv($annualRate, '100', 12), '365', 12);
            $financingBalances = $kind === 'credit_card'
                ? $this->cardFinancingBalances($debt, $commitment, $balance, $movements, $start, $through)
                : [
                    'daily' => $balances['daily'],
                    'balance' => $balances['balance'],
                    'projected_balance' => $balances['balance'],
                    'source' => $balances['source'],
                    'status' => 'accruing',
                    'statement_balance' => null,
                    'financing_starts_on' => null,
                    'detail' => sprintf('Saldo pendiente × tasa anual de %s%% ÷ 365 durante los días del corte.', $annualRate),
                ];
            $accruedInterestRaw = '0.000000000000';

            foreach ($financingBalances['daily'] as $dayBalance) {
                if (bccomp($dayBalance['balance'], '0.00', 2) > 0) {
                    $accruedInterestRaw = bcadd(
                        $accruedInterestRaw,
                        bcmul($dayBalance['balance'], $dailyRate, 12),
                        12,
                    );
                }
            }
            $accruedInterest = $this->roundMoney($accruedInterestRaw);
            $dailyInterestIfUnpaid = $this->roundMoney(
                bcmul($financingBalances['projected_balance'], $dailyRate, 12),
            );
            $fee = $kind === 'credit_card'
                ? $this->overdraftCharge($product, $balance, $movements, $balances['daily'], $start, $through)
                : ['amount' => '0.00', 'status' => 'not_applicable', 'detail' => 'No aplica a préstamos.'];
            $rateSource = $rateMissing ? 'Tasa no registrada' : ($conditions['explanation']['rate_source'] ?? 'Contrato individual');
            $interestLabel = $kind === 'credit_card' ? 'Interés de financiamiento' : 'Interés de préstamo';
            $total = bcadd($accruedInterest, $fee['amount'], 2);

            $items[] = [
                'id' => $entry['id'],
                'debt_id' => $debt?->id,
                'name' => $entry['name'],
                'kind' => $kind,
                'kind_label' => $kind === 'credit_card' ? 'Tarjeta de crédito' : 'Préstamo',
                'balance' => $balance,
                'balance_source' => $balances['source'],
                'statement_balance' => $financingBalances['statement_balance'],
                'financed_balance' => $financingBalances['balance'],
                'projected_financed_balance' => $financingBalances['projected_balance'],
                'daily_interest_if_unpaid' => $dailyInterestIfUnpaid,
                'financing_starts_on' => $financingBalances['financing_starts_on'],
                'financing_status' => $financingBalances['status'],
                'annual_rate' => $annualRate,
                'rate_source' => $rateSource,
                'daily_interest_rate' => bcmul($dailyRate, '100', 8),
                'financing_interest' => $accruedInterest,
                'financing_interest_label' => $interestLabel,
                'financing_interest_detail' => $financingBalances['detail'],
                'overdraft_cut_charge' => $fee['amount'],
                'overdraft_charge_status' => $fee['status'],
                'overdraft_charge_detail' => $fee['detail'],
                'total' => $total,
                'rate_missing' => $rateMissing,
            ];

            if ($kind === 'credit_card') {
                $cardFinancingInterest = bcadd($cardFinancingInterest, $accruedInterest, 2);
            } else {
                $loanInterest = bcadd($loanInterest, $accruedInterest, 2);
            }
            $overdraftCharge = bcadd($overdraftCharge, $fee['amount'], 2);
        }

        return [
            'period' => [
                'pay_frequency' => $frequency,
                'label' => $periodLabel,
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(),
                'calculated_through' => $through->toDateString(),
                'days_elapsed' => $daysElapsed,
            ],
            'summary' => [
                'card_financing_interest' => $cardFinancingInterest,
                'loan_interest' => $loanInterest,
                'financing_interest' => bcadd($cardFinancingInterest, $loanInterest, 2),
                'overdraft_cut_charge' => $overdraftCharge,
                'total' => bcadd(bcadd($cardFinancingInterest, $loanInterest, 2), $overdraftCharge, 2),
            ],
            'items' => $items,
        ];
    }

    private function period(Carbon $today, string $frequency, string $weekStarts): array
    {
        $start = $today->copy();
        $end = $today->copy();

        if ($frequency === 'weekly') {
            $weekStart = $weekStarts === 'Sunday' ? Carbon::SUNDAY : Carbon::MONDAY;
            $start->startOfWeek($weekStart);
            $end = $start->copy()->addDays(6);
            $label = 'Semanal';
        } elseif ($frequency === 'biweekly') {
            if ($today->day <= 15) {
                $start->startOfMonth();
                $end->day(15);
                $label = 'Quincenal · 1–15';
            } else {
                $start->startOfMonth()->day(16);
                $end->endOfMonth();
                $label = 'Quincenal · 16–fin de mes';
            }
        } else {
            $start->startOfMonth();
            $end->endOfMonth();
            $label = 'Mensual';
        }

        return [$start->startOfDay(), $end->startOfDay(), $label];
    }

    private function dailyBalances(string $currentBalance, Collection $movements, Carbon $start, Carbon $through): array
    {
        $periodMovements = $movements->filter(fn ($movement) =>
            $movement->occurred_at->gte($start) && $movement->occurred_at->lte($through)
        )->values();

        if ($periodMovements->isEmpty()) {
            $balance = $currentBalance;
            $daily = [];
            for ($day = $start->copy(); $day->lte($through); $day->addDay()) {
                $daily[] = ['date' => $day->toDateString(), 'balance' => $balance];
            }
            return ['daily' => $daily, 'balance' => $balance, 'source' => 'current_balance'];
        }

        $periodDelta = '0.00';
        foreach ($periodMovements as $movement) {
            $periodDelta = bcadd($periodDelta, (string) $movement->delta, 2);
        }
        $balance = bcsub($currentBalance, $periodDelta, 2);
        $index = 0;
        $daily = [];
        for ($day = $start->copy(); $day->lte($through); $day->addDay()) {
            $endOfDay = $day->copy()->endOfDay()->min($through);
            while ($index < $periodMovements->count() && $periodMovements[$index]->occurred_at->lte($endOfDay)) {
                $balance = bcadd($balance, (string) $periodMovements[$index]->delta, 2);
                $index++;
            }
            if (bccomp($balance, '0.00', 2) < 0) {
                $balance = '0.00';
            }
            $daily[] = ['date' => $day->toDateString(), 'balance' => $balance];
        }

        return ['daily' => $daily, 'balance' => $balance, 'source' => 'current_balance_and_period_movements'];
    }

    private function cardFinancingBalances(
        ?Debt $debt,
        ?Commitment $commitment,
        string $currentBalance,
        Collection $movements,
        Carbon $start,
        Carbon $through,
    ): array {
        $statement = $debt
            ? CreditCardStatement::query()
                ->where('debt_id', $debt->id)
                ->whereDate('due_date', '<=', $through->toDateString())
                ->orderByDesc('due_date')
                ->first()
            : null;
        $statementBalance = $statement?->closing_balance
            ?? $debt?->statement_balance
            ?? $commitment?->statement_balance;
        $dueDate = $statement?->due_date?->copy()->startOfDay()
            ?? $debt?->due_date?->copy()->startOfDay()
            ?? $commitment?->next_due_date?->copy()->startOfDay();
        $zeroDaily = [];
        for ($day = $start->copy(); $day->lte($through); $day->addDay()) {
            $zeroDaily[] = ['date' => $day->toDateString(), 'balance' => '0.00'];
        }

        if ($dueDate === null || $statementBalance === null) {
            return [
                'daily' => $zeroDaily,
                'balance' => '0.00',
                'projected_balance' => '0.00',
                'source' => 'statement_unavailable',
                'status' => 'statement_unavailable',
                'statement_balance' => $statementBalance === null ? null : (string) $statementBalance,
                'financing_starts_on' => null,
                'detail' => 'No hay saldo facturado y fecha de vencimiento suficientes para identificar capital financiado.',
            ];
        }

        $statementBalance = (string) $statementBalance;
        $financingStart = $dueDate->copy()->addDay()->startOfDay();
        $statementCutoff = $statement?->statement_date?->copy()->endOfDay();
        if (!$statementCutoff) {
            $graceDays = $debt?->payment_grace_days ?? $commitment?->payment_grace_days;
            $statementCutoff = $graceDays !== null
                ? $dueDate->copy()->subDays((int) $graceDays)->endOfDay()
                : $dueDate->copy()->endOfDay();
        }
        $eligiblePayments = $movements->filter(function (DebtBalanceMovement $movement) use ($statementCutoff, $dueDate, $through) {
            if (!in_array($movement->kind, ['payment', 'reversal'], true) || $movement->occurred_at->gt($through)) {
                return false;
            }
            $afterStatement = $statementCutoff
                ? $movement->occurred_at->gt($statementCutoff)
                : $movement->occurred_at->gt($dueDate->copy()->endOfDay());
            return $afterStatement;
        })->values();

        $statementCutoff = $statement?->statement_date?->copy()->endOfDay()
            ?? $statementCutoff;
        $alreadyIncludedCharges = $movements
            ->filter(fn (DebtBalanceMovement $movement) =>
                in_array($movement->kind, ['financing_interest', 'overdraft_fee'], true)
                && (!$statementCutoff || $movement->occurred_at->lte($statementCutoff))
            )
            ->sum('delta');
        $statementBalance = bcsub($statementBalance, (string) $alreadyIncludedCharges, 2);

        $projectedFinancedBalance = $statementBalance;
        foreach ($eligiblePayments as $payment) {
            if ($payment->occurred_at->lt($financingStart)) {
                $projectedFinancedBalance = bcadd($projectedFinancedBalance, (string) $payment->delta, 2);
            }
        }
        if (bccomp($projectedFinancedBalance, '0.00', 2) < 0) {
            $projectedFinancedBalance = '0.00';
        }

        if ($financingStart->gt($through)) {
            return [
                'daily' => $zeroDaily,
                'balance' => '0.00',
                'projected_balance' => $projectedFinancedBalance,
                'source' => $statement ? 'credit_card_statement' : 'current_statement_balance',
                'status' => 'grace_period',
                'statement_balance' => $statementBalance,
                'financing_starts_on' => $financingStart->toDateString(),
                'detail' => sprintf('Saldo facturado %s pendiente; está en periodo de gracia hasta %s.', $statementBalance, $dueDate->toDateString()),
            ];
        }

        $accrualStart = $start->gt($financingStart) ? $start->copy() : $financingStart;
        $financedBalance = $statementBalance;
        $financedBalance = $projectedFinancedBalance;

        $paymentsDuringAccrual = $eligiblePayments
            ->filter(fn (DebtBalanceMovement $payment) => $payment->occurred_at->gte($accrualStart))
            ->values();
        $paymentIndex = 0;
        $daily = [];
        $balance = $financedBalance;
        for ($day = $start->copy(); $day->lte($through); $day->addDay()) {
            if ($day->lt($accrualStart)) {
                $daily[] = ['date' => $day->toDateString(), 'balance' => '0.00'];
                continue;
            }
            $endOfDay = $day->copy()->endOfDay()->min($through);
            while ($paymentIndex < $paymentsDuringAccrual->count() && $paymentsDuringAccrual[$paymentIndex]->occurred_at->lte($endOfDay)) {
                $balance = bcadd($balance, (string) $paymentsDuringAccrual[$paymentIndex]->delta, 2);
                $paymentIndex++;
            }
            if (bccomp($balance, '0.00', 2) < 0) {
                $balance = '0.00';
            }
            $daily[] = ['date' => $day->toDateString(), 'balance' => $balance];
        }

        $status = bccomp($balance, '0.00', 2) > 0 ? 'accruing' : 'paid';
        return [
            'daily' => $daily,
            'balance' => $balance,
            'projected_balance' => $balance,
            'source' => $statement ? 'credit_card_statement' : 'current_statement_balance',
            'status' => $status,
            'statement_balance' => $statementBalance,
            'financing_starts_on' => $financingStart->toDateString(),
            'detail' => sprintf('Capital financiado: saldo facturado %s menos pagos posteriores al corte; interés diario desde el día siguiente al vencimiento (%d días del periodo).', $statementBalance, count(array_filter($daily, fn ($item) => bccomp($item['balance'], '0.00', 2) > 0))),
        ];
    }

    private function overdraftCharge(
        Debt|Commitment $product,
        string $currentBalance,
        Collection $movements,
        array $dailyBalances,
        Carbon $start,
        Carbon $through,
    ): array {
        $actualFees = $movements->filter(fn (DebtBalanceMovement $movement) =>
            $movement->kind === 'overdraft_fee'
            && $movement->occurred_at->gte($start)
            && $movement->occurred_at->lte($through)
        );

        if ($actualFees->isNotEmpty()) {
            return [
                'amount' => (string) $actualFees->sum('delta'),
                'status' => 'posted',
                'detail' => sprintf('Cargo por sobregiro contabilizado en el corte (%d movimiento(s)).', $actualFees->count()),
            ];
        }

        $mode = (string) ($product->overdraft_fee_type ?? 'none');
        if (!in_array($mode, ['cutoff_fixed', 'cutoff_percentage', 'cutoff_both', 'cutoff_daily'], true)) {
            return ['amount' => '0.00', 'status' => 'not_configured', 'detail' => 'No hay cobro por sobregiro al corte configurado.'];
        }

        $limit = $product->approved_credit_limit ?? $product->credit_limit;
        if ($limit === null) {
            return ['amount' => '0.00', 'status' => 'missing_limit', 'detail' => 'Falta registrar el límite aprobado para estimar el cobro.'];
        }

        $limit = (string) $limit;
        $fixed = (string) ($product->overdraft_fee_amount ?? '0.00');
        $rate = (string) ($product->overdraft_fee_percentage ?? '0.00');
        $amount = '0.000000000000';

        if ($mode === 'cutoff_daily') {
            $dailyRate = bcdiv(bcdiv($rate, '100', 12), '365', 12);
            foreach ($dailyBalances as $dayBalance) {
                $excess = bcsub($dayBalance['balance'], $limit, 2);
                if (bccomp($excess, '0.00', 2) > 0) {
                    $amount = bcadd($amount, bcmul($excess, $dailyRate, 12), 12);
                }
            }
        } else {
            $closingBalance = $dailyBalances === [] ? $currentBalance : $dailyBalances[array_key_last($dailyBalances)]['balance'];
            $excess = bcsub($closingBalance, $limit, 2);
            if (bccomp($excess, '0.00', 2) > 0) {
                $percentage = bcmul($excess, bcdiv($rate, '100', 10), 10);
                $amount = match ($mode) {
                    'cutoff_fixed' => $fixed,
                    'cutoff_percentage' => $percentage,
                    'cutoff_both' => bcadd($fixed, $percentage, 10),
                };
            }
        }

        $rounded = $this->roundMoney($amount);
        return [
            'amount' => $rounded,
            'status' => bccomp($rounded, '0.00', 2) > 0 ? 'estimated' : 'not_applicable',
            'detail' => $mode === 'cutoff_daily'
                ? sprintf('Estimado diario sobre el exceso del límite durante %d día(s); se contabiliza al corte bancario.', count($dailyBalances))
                : 'Estimado según el saldo y las condiciones actuales; se contabiliza al corte bancario.',
        ];
    }

    private function roundMoney(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        $digits = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');
        $cents = (int) substr($fraction, 0, 2);
        if ((int) substr($fraction, 2, 1) >= 5) {
            $cents++;
        }
        if ($cents >= 100) {
            $whole = bcadd($whole ?: '0', '1', 0);
            $cents = 0;
        }
        return ($negative ? '-' : '') . ($whole ?: '0') . '.' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
    }
}