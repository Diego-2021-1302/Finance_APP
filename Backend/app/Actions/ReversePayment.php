<?php

namespace App\Actions;

use App\Models\FinancialTransaction;
use App\Models\CommitmentOccurrence;
use App\Models\Commitment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReversePayment
{
    public function execute(User $user, Payment $payment, array $input, RecalculateCommitmentAmortization $amortization): Payment
    {
        return DB::transaction(function () use ($user, $payment, $input, $amortization) {
            $locked = Payment::query()->where('user_id', $user->id)->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->reversal_key === $input['idempotency_key']) return $locked;
            if ($locked->reversed_at !== null) throw ValidationException::withMessages(['payment' => 'Este pago ya ha sido revertido.']);

            $original = FinancialTransaction::query()->where('payment_id', $locked->id)->firstOrFail();
            $occurrence = $locked->commitment_occurrence_id
                ? CommitmentOccurrence::query()->whereKey($locked->commitment_occurrence_id)->first()
                : null;
            $commitment = $occurrence
                ? Commitment::query()->where('user_id', $user->id)->whereKey($occurrence->commitment_id)->lockForUpdate()->first()
                : null;
            $locked->update(['reversed_at' => now(), 'reversal_reason' => $input['reason'], 'reversal_key' => $input['idempotency_key']]);
            FinancialTransaction::query()->create([
                'user_id' => $user->id, 'account_id' => $original->account_id, 'payment_id' => $locked->id,
                'description' => 'Reversal: '.$input['reason'], 'amount' => bcsub('0.00', (string) $original->amount, 2),
                'occurred_at' => now(), 'kind' => 'reversal', 'reversal_of_id' => $original->id,
            ]);
            if ($occurrence) {
                $remainingPaid = (string) $occurrence->payments()->whereNull('reversed_at')->sum('amount');
                $occurrence->update([
                    'status' => bccomp($remainingPaid, (string) $occurrence->amount_due, 2) >= 0 ? 'paid' : 'scheduled',
                ]);
                if ($commitment && $occurrence->status !== 'paid') {
                    $hasLaterPayments = CommitmentOccurrence::query()
                        ->where('commitment_id', $occurrence->commitment_id)
                        ->whereDate('due_date', '>', $occurrence->due_date)
                        ->whereHas('payments', fn ($query) => $query->whereNull('reversed_at'))
                        ->exists();
                    if (!$hasLaterPayments) {
                        $commitment?->update(['next_due_date' => $occurrence->due_date, 'is_active' => true]);
                        CommitmentOccurrence::query()
                            ->where('commitment_id', $occurrence->commitment_id)
                            ->whereDate('due_date', '>', $occurrence->due_date)
                            ->where('status', '!=', 'paid')
                            ->whereDoesntHave('payments')
                            ->delete();
                    }
                }
                if ($commitment) $amortization->execute($commitment);
            }
            return $locked->refresh();
        }, 3);
    }
}
