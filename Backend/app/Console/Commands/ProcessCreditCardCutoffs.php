<?php

namespace App\Console\Commands;

use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\Debt;
use Illuminate\Console\Command;

class ProcessCreditCardCutoffs extends Command
{
    protected $signature = 'finance:process-card-cutoffs';
    protected $description = 'Process credit card statement cut-offs, update statement balances and compute payment due dates';

    public function handle(): int
    {
        $today = now();

        Commitment::query()
            ->where('type', 'credit_card')
            ->where('is_active', true)
            ->whereNotNull('statement_day')
            ->chunkById(100, function ($commitments) use ($today) {
                foreach ($commitments as $commitment) {
                    $statementDay = min((int) $commitment->statement_day, $today->daysInMonth);

                    // If today is statement cut-off day
                    if ($today->day === $statementDay) {
                        $graceDays = (int) ($commitment->payment_grace_days ?? 21);
                        $dueDate = $today->copy()->addDays($graceDays)->toDateString();
                        $statementBalance = (string) $commitment->current_balance;

                        $commitment->update([
                            'statement_balance' => $statementBalance,
                            'next_due_date' => $dueDate,
                            'amount' => bccomp($statementBalance, '0.00', 2) > 0 ? $statementBalance : '0.00',
                        ]);

                        // Sync with Debt
                        $minimum = (string) round(((float) $statementBalance) * 0.05, 2);
                        Debt::query()
                            ->where('user_id', $commitment->user_id)
                            ->where('name', $commitment->name)
                            ->update([
                                'statement_balance' => $statementBalance,
                                'due_date' => $dueDate,
                                'minimum_payment' => $minimum,
                            ]);

                        // Schedule statement payment occurrence
                        if (bccomp($statementBalance, '0.00', 2) > 0) {
                            CommitmentOccurrence::query()->firstOrCreate(
                                [
                                    'commitment_id' => $commitment->id,
                                    'due_date' => $dueDate,
                                    'schedule_version' => 1,
                                ],
                                [
                                    'user_id' => $commitment->user_id,
                                    'amount_due' => $statementBalance,
                                    'status' => 'scheduled',
                                ]
                            );
                        }
                    }
                }
            });

        $this->info('Credit card cut-offs processed successfully.');
        return self::SUCCESS;
    }
}
