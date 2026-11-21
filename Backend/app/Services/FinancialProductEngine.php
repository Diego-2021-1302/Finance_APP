<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\Debt;
use Illuminate\Support\Carbon;

class FinancialProductEngine
{
    /**
     * Resolve applicable contractual financial conditions following the 4-level priority hierarchy:
     * 1. Individual User Contract Overrides (explicit properties on entity)
     * 2. Active Promotional Rate / Exemption (if promo_until_date >= operation_date)
     * 3. Linked Product Variant (FinancialProductVariant)
     * 4. Valores manuales individuales del producto financiero
     */
    public function resolveApplicableConditions(Debt|Commitment|Account $entity, ?string $operationDate = null): array
    {
        $opDate = $operationDate ? Carbon::parse($operationDate) : now();

        // 1. Check active promotional rate / exemption on entity
        $isPromoActive = false;
        $promoRate = null;
        if ($entity->promo_until_date && $opDate->lte($entity->promo_until_date) && $entity->promo_interest_rate !== null) {
            $isPromoActive = true;
            $promoRate = (string) $entity->promo_interest_rate;
        }

        // Resolve Annual Interest Rate
        $rateSource = 'Individual User Contract';
        $resolvedRate = '0.00';

        if ($isPromoActive) {
            $resolvedRate = $promoRate;
            $rateSource = sprintf('Promoción activa hasta %s', $entity->promo_until_date->toDateString());
        } elseif ($entity->interest_rate !== null && bccomp((string) $entity->interest_rate, '0.00', 3) > 0) {
            $resolvedRate = (string) $entity->interest_rate;
            $rateSource = 'Tasa individual en contrato';
        }

        // Resolve Overdraft Fee Rules
        $feeSource = 'Contrato individual';
        $resolvedFeeType = $entity->overdraft_fee_type ?? 'none';
        $resolvedFeeAmount = (string) ($entity->overdraft_fee_amount ?? '0.00');
        $resolvedFeePct = (string) ($entity->overdraft_fee_percentage ?? '0.00');
        $resolvedTaxRate = (string) ($entity->overdraft_fee_tax_rate ?? '0.00');

        // Resolve Amortization & Other Charges
        $resolvedAmortType = $entity->amortization_type ?? 'french';
        $resolvedTermMonths = $entity->term_months ?? 12;
        $resolvedAdvanceRate = (string) ($entity->cash_advance_interest_rate ?? $resolvedRate);
        $resolvedMoraRate = (string) ($entity->moratorium_interest_rate ?? '0.00');
        $resolvedMembership = (string) ($entity->membership_fee_amount ?? '0.00');
        $resolvedInsurance = (string) ($entity->insurance_fee_amount ?? '0.00');
        $resolvedLatePct = (string) ($entity->late_fee_percentage ?? '0.00');
        $resolvedLateFixed = (string) ($entity->late_fee_fixed ?? '0.00');

        return [
            'annual_interest_rate' => $resolvedRate,
            'is_promotional' => $isPromoActive,
            'overdraft_fee_type' => $resolvedFeeType,
            'overdraft_fee_amount' => $resolvedFeeAmount,
            'overdraft_fee_percentage' => $resolvedFeePct,
            'overdraft_fee_tax_rate' => $resolvedTaxRate,
            'cash_advance_interest_rate' => $resolvedAdvanceRate,
            'moratorium_interest_rate' => $resolvedMoraRate,
            'membership_fee_amount' => $resolvedMembership,
            'insurance_fee_amount' => $resolvedInsurance,
            'late_fee_percentage' => $resolvedLatePct,
            'late_fee_fixed' => $resolvedLateFixed,
            'amortization_type' => $resolvedAmortType,
            'term_months' => $resolvedTermMonths,
            'explanation' => [
                'rate_source' => $rateSource,
                'fee_source' => $feeSource,
                'rule_used' => sprintf('Jerarquía de resolución: %s (Tasa %s%%)', $rateSource, $resolvedRate),
                'verified_official_source' => null,
            ],
        ];
    }

    /**
     * Calculate interest for a given principal balance and annual rate.
     * Returns contractual_calculable classification and formula explanation.
     */
    public function calculateInterest(string $principalBalance, string $annualRate, string $frequency = 'monthly'): array
    {
        $periodsPerYear = match ($frequency) {
            'weekly' => '52',
            'biweekly' => '26',
            'monthly' => '12',
            'quarterly' => '4',
            'yearly' => '1',
            default => '12',
        };

        if (bccomp($principalBalance, '0.00', 2) <= 0 || bccomp($annualRate, '0.00', 3) <= 0) {
            return [
                'interest' => '0.00',
                'classification' => 'contractual_calculable',
                'formula' => sprintf('(%s * (%s / 100)) / %s = 0.00', $principalBalance, $annualRate, $periodsPerYear),
            ];
        }

        // Periodic Rate = annualRate / (100 * periodsPerYear)
        $rateDecimal = bcdiv($annualRate, '100', 8);
        $periodicRate = bcdiv($rateDecimal, $periodsPerYear, 8);
        $interest = bcmul($principalBalance, $periodicRate, 2);

        return [
            'interest' => $interest,
            'classification' => 'contractual_calculable',
            'formula' => sprintf('(%s * (%s / 100)) / %s = %s', $principalBalance, $annualRate, $periodsPerYear, $interest),
        ];
    }

    /**
     * Calculate French (Cuotas Fijas) amortization schedule for loans.
     */
    public function calculateFrenchAmortization(string $principal, string $annualRate, int $termMonths): array
    {
        if ($termMonths <= 0 || bccomp($principal, '0.00', 2) <= 0) {
            return ['installment' => '0.00', 'schedule' => []];
        }

        $rateDecimal = bcdiv($annualRate, '100', 8);
        $monthlyRate = bcdiv($rateDecimal, '12', 8);

        // PMT = P * [r(1+r)^n] / [(1+r)^n - 1]
        if (bccomp($monthlyRate, '0.00', 8) === 0) {
            $installment = bcdiv($principal, (string) $termMonths, 2);
        } else {
            $base = bcadd('1.00000000', $monthlyRate, 8);
            $factor = $this->decimalPower($base, $termMonths, 12);
            $numerator = bcmul(
                bcmul($principal, $monthlyRate, 12),
                $factor,
                12,
            );
            $denominator = bcsub($factor, '1.000000000000', 12);
            $installment = $this->roundMoney(bcdiv($numerator, $denominator, 12));
        }

        $schedule = [];
        $remainingBalance = $principal;

        for ($month = 1; $month <= $termMonths; $month++) {
            $interestPortion = bcmul($remainingBalance, $monthlyRate, 2);
            $principalPortion = bcsub($installment, $interestPortion, 2);

            if ($month === $termMonths || bccomp($principalPortion, $remainingBalance, 2) >= 0) {
                $principalPortion = $remainingBalance;
                $installmentForMonth = bcadd($principalPortion, $interestPortion, 2);
                $remainingBalance = '0.00';
            } else {
                $installmentForMonth = $installment;
                $remainingBalance = bcsub($remainingBalance, $principalPortion, 2);
            }

            $schedule[] = [
                'month' => $month,
                'installment' => $installmentForMonth,
                'principal_portion' => $principalPortion,
                'interest_portion' => $interestPortion,
                'remaining_balance' => $remainingBalance,
                'classification' => 'contractual_calculable',
            ];

            if (bccomp($remainingBalance, '0.00', 2) === 0) break;
        }

        return [
            'installment' => $installment,
            'schedule' => $schedule,
            'classification' => 'contractual_calculable',
        ];
    }

    private function decimalPower(string $base, int $exponent, int $scale): string
    {
        $result = '1.' . str_repeat('0', $scale);
        $factor = $base;
        while ($exponent > 0) {
            if (($exponent & 1) === 1) {
                $result = bcmul($result, $factor, $scale);
            }
            $exponent >>= 1;
            if ($exponent > 0) {
                $factor = bcmul($factor, $factor, $scale);
            }
        }
        return $result;
    }

    private function roundMoney(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');
        $cents = (int) substr($fraction, 0, 2);
        if ((int) substr($fraction, 2, 1) >= 5) {
            $cents++;
        }
        if ($cents >= 100) {
            $whole = bcadd($whole ?: '0', '1', 0);
            $cents = 0;
        }
        $result = ($whole ?: '0') . '.' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
        return $negative ? '-' . $result : $result;
    }

    public function calculateDailyBalanceInterest(
        string $averageDailyBalance,
        string $annualRate,
        int $daysInPeriod,
    ): array {
        if ($daysInPeriod < 0 || bccomp($averageDailyBalance, '0.00', 2) <= 0
            || bccomp($annualRate, '0.00', 3) <= 0) {
            return [
                'interest' => '0.00',
                'classification' => 'contractual_calculable',
                'formula' => '0.00',
            ];
        }

        $dailyRate = bcdiv(bcdiv($annualRate, '100', 10), '365', 10);
        $interest = $this->roundMoney(
            bcmul(bcmul($averageDailyBalance, $dailyRate, 12), (string) $daysInPeriod, 12),
        );

        return [
            'interest' => $interest,
            'classification' => 'contractual_calculable',
            'formula' => sprintf(
                'Saldo diario promedio %s * (tasa anual %s%% / 365) * %d días',
                $averageDailyBalance,
                $annualRate,
                $daysInPeriod,
            ),
        ];
    }

    public function calculateCardPaymentApplication(
        string $paymentAmount,
        array $balances,
        array $taxRates = [],
    ): array {
        $remaining = $this->roundMoney($paymentAmount);
        $applied = [];

        $order = ['moratorium', 'late_fee', 'advances', 'purchases', 'principal'];
        foreach ($order as $bucket) {
            $balance = $this->roundMoney((string) ($balances[$bucket] ?? '0.00'));
            $amount = bccomp($remaining, $balance, 2) >= 0 ? $balance : $remaining;
            $amount = bccomp($amount, '0.00', 2) > 0 ? $amount : '0.00';
            $applied[$bucket] = $amount;
            $remaining = bcsub($remaining, $amount, 2);
        }

        $taxApplied = '0.00';
        foreach ($applied as $bucket => $amount) {
            $rate = (string) ($taxRates[$bucket] ?? '0.00');
            if (bccomp($amount, '0.00', 2) > 0 && bccomp($rate, '0.00', 2) > 0) {
                $taxApplied = bcadd(
                    $taxApplied,
                    $this->roundMoney(bcmul($amount, bcdiv($rate, '100', 8), 12)),
                    2,
                );
            }
        }

        return [
            'applied' => $applied,
            'remaining_payment' => $remaining,
            'tax_applied' => $taxApplied,
            'classification' => 'contractual_requires_documented_precedence',
        ];
    }

    /**
     * Calculate late fee and tax based on contractual rules.
     */
    public function calculateLateFee(string $amountOverdue, int $daysOverdue, Debt|Commitment $entity): array
    {
        if ($daysOverdue <= 0 || bccomp($amountOverdue, '0.00', 2) <= 0) {
            return [
                'fee' => '0.00',
                'tax' => '0.00',
                'total_charge' => '0.00',
                'classification' => 'contractual_calculable',
            ];
        }

        $conditions = $this->resolveApplicableConditions($entity);
        $latePct = $conditions['late_fee_percentage'];
        $lateFixed = $conditions['late_fee_fixed'];
        $taxRate = $conditions['overdraft_fee_tax_rate'];

        // Percentage portion
        $pctFee = '0.00';
        if (bccomp($latePct, '0.00', 2) > 0) {
            $pctDecimal = bcdiv($latePct, '100', 6);
            $pctFee = bcmul($amountOverdue, $pctDecimal, 2);
        }

        $totalFeeBeforeTax = bcadd($lateFixed, $pctFee, 2);

        // Tax portion (e.g. 18% ITBIS on bank fees)
        $tax = '0.00';
        if (bccomp($taxRate, '0.00', 2) > 0 && bccomp($totalFeeBeforeTax, '0.00', 2) > 0) {
            $taxDecimal = bcdiv($taxRate, '100', 6);
            $tax = bcmul($totalFeeBeforeTax, $taxDecimal, 2);
        }

        $totalCharge = bcadd($totalFeeBeforeTax, $tax, 2);

        return [
            'fee' => $totalFeeBeforeTax,
            'tax' => $tax,
            'total_charge' => $totalCharge,
            'classification' => 'contractual_calculable',
            'explanation' => sprintf(
                'Mora calculada: %s fija + %s%% sobre mora (RD$ %s) + %s%% impuestos (RD$ %s) = RD$ %s',
                $lateFixed,
                $latePct,
                $pctFee,
                $taxRate,
                $tax,
                $totalCharge
            ),
        ];
    }
}
