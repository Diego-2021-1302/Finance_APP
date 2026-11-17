<?php

namespace App\Actions;

use App\Models\Commitment;
use App\Models\CommitmentOccurrence;

class RecalculateCommitmentAmortization
{
    public function interestFor(string $balance, string $annualRate, string $frequency): string
    {
        $periodsPerYear = match ($frequency) {
            'weekly' => 52,
            'biweekly' => 26,
            'monthly' => 12,
            'quarterly' => 4,
            'yearly' => 1,
            default => 12,
        };
        $interest = bcdiv(bcmul($balance, $annualRate, 8), (string) (100 * $periodsPerYear), 8);

        return bcadd($interest, '0.005', 2);
    }

    public function execute(Commitment $commitment): void
    {
        if (!in_array($commitment->type, ['loan', 'credit_card'], true) || $commitment->opening_balance === null) {
            return;
        }

        $lastPaidDate = CommitmentOccurrence::query()
            ->where('commitment_id', $commitment->id)
            ->whereHas('payments', fn ($query) => $query->whereNull('reversed_at'))
            ->max('due_date');

        if ($lastPaidDate === null) {
            CommitmentOccurrence::query()
                ->where('commitment_id', $commitment->id)
                ->whereNotNull('interest_accrued')
                ->update(['interest_accrued' => null]);
            $commitment->update(['current_balance' => $commitment->opening_balance]);
            return;
        }

        CommitmentOccurrence::query()
            ->where('commitment_id', $commitment->id)
            ->whereDate('due_date', '>', $lastPaidDate)
            ->whereNotNull('interest_accrued')
            ->update(['interest_accrued' => null]);

        $balance = (string) $commitment->opening_balance;
        $occurrences = CommitmentOccurrence::query()
            ->where('commitment_id', $commitment->id)
            ->whereDate('due_date', '<=', $lastPaidDate)
            ->orderBy('due_date')
            ->get();

        foreach ($occurrences as $occurrence) {
            $interest = $this->interestFor($balance, (string) $commitment->interest_rate, $commitment->frequency);
            $occurrence->update(['interest_accrued' => $interest]);
            $paid = (string) $occurrence->payments()->whereNull('reversed_at')->sum('amount');
            $balanceBeforePayment = bcadd($balance, $interest, 2);
            $balance = bccomp($paid, $balanceBeforePayment, 2) >= 0
                ? '0.00'
                : bcsub($balanceBeforePayment, $paid, 2);
        }

        $commitment->update(['current_balance' => $balance]);
    }
}
