<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\CreditCardStatement;
use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use App\Services\DebtBalanceLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProcessCreditCardCutoffs extends Command
{
    protected $signature = 'finance:process-card-cutoffs';
    protected $description = 'Process missed credit card cut-offs and save immutable statement history';

    public function handle(DebtBalanceLedger $balanceLedger): int
    {
        $now = now();
        $eligibleThrough = $now->copy()->subDay()->endOfDay();

        Commitment::query()
            ->where('type', 'credit_card')
            ->where('is_active', true)
            ->whereNotNull('statement_day')
            ->chunkById(100, function ($commitments) use ($now, $eligibleThrough, $balanceLedger) {
                foreach ($commitments as $commitment) {
                    DB::transaction(function () use ($commitment, $now, $eligibleThrough, $balanceLedger) {
                        $card = Commitment::query()
                            ->whereKey($commitment->id)
                            ->where('is_active', true)
                            ->lockForUpdate()
                            ->first();

                        if (!$card || !$card->statement_day) {
                            return;
                        }

                        $account = Account::query()
                            ->where('user_id', $card->user_id)
                            ->where('name', $card->name)
                            ->where('type', 'credit_card')
                            ->firstOrFail();
                        $debt = Debt::query()
                            ->where('user_id', $card->user_id)
                            ->where('kind', 'credit_card')
                            ->where(function ($query) use ($account, $card) {
                                $query->where('account_id', $account->id)
                                    ->orWhere(function ($fallback) use ($card) {
                                        $fallback->whereNull('account_id')->where('name', $card->name);
                                    });
                            })
                            ->lockForUpdate()
                            ->firstOrFail();

                        $balanceLedger->ensureOpeningBalance($debt, $account->id, $now);

                        $movements = DebtBalanceMovement::query()
                            ->where('debt_id', $debt->id)
                            ->where('occurred_at', '<=', $now)
                            ->orderBy('occurred_at')
                            ->orderBy('id')
                            ->get();

                        if ($movements->isEmpty()) {
                            return;
                        }

                        $baselineDate = Carbon::parse($movements->first()->occurred_at);
                        $statementDay = max(1, (int) $card->statement_day);
                        $lastCutoff = $eligibleThrough->copy()->startOfMonth()->day(min($statementDay, $eligibleThrough->daysInMonth));
                        if ($lastCutoff->gt($eligibleThrough)) {
                            $lastCutoff->subMonthNoOverflow();
                            $lastCutoff->day(min($statementDay, $lastCutoff->daysInMonth));
                        }

                        $firstMonth = $baselineDate->copy()->startOfMonth();
                        $lastMonth = $lastCutoff->copy()->startOfMonth();
                        $existingLatestDate = CreditCardStatement::query()
                            ->where('commitment_id', $card->id)
                            ->max('statement_date');
                        $existingLatestDate = $existingLatestDate
                            ? Carbon::parse($existingLatestDate)
                            : null;
                        $latestNewStatement = null;
                        $movementIndex = 0;
                        $statementBalance = '0.00';
                        $currentBalance = '0.00';

                        foreach ($movements as $movement) {
                            $currentBalance = bcadd($currentBalance, (string) $movement->delta, 2);
                        }

                        while ($firstMonth->lte($lastMonth)) {
                            $cutoffDate = $firstMonth->copy()->day(min($statementDay, $firstMonth->daysInMonth));
                            $cutoffEnd = $cutoffDate->copy()->endOfDay();

                            while (
                                $movementIndex < $movements->count()
                                && $movements[$movementIndex]->occurred_at->lte($cutoffEnd)
                            ) {
                                $statementBalance = bcadd(
                                    $statementBalance,
                                    (string) $movements[$movementIndex]->delta,
                                    2,
                                );
                                $movementIndex++;
                            }

                            if ($cutoffEnd->lt($baselineDate)) {
                                $firstMonth->addMonthNoOverflow();
                                continue;
                            }

                            $statement = CreditCardStatement::query()
                                ->where('commitment_id', $card->id)
                                ->whereDate('statement_date', $cutoffDate->toDateString())
                                ->first();

                            if (!$statement) {
                                $dueDate = $cutoffDate->copy()->addDays((int) ($card->payment_grace_days ?? 21));
                                $statement = CreditCardStatement::query()->create([
                                    'user_id' => $card->user_id,
                                    'commitment_id' => $card->id,
                                    'debt_id' => $debt->id,
                                    'statement_date' => $cutoffDate->toDateString(),
                                    'closing_balance' => $statementBalance,
                                    'due_date' => $dueDate->toDateString(),
                                ]);

                                $this->syncPaymentOccurrence($card, $statement);
                                if (!$existingLatestDate || $cutoffDate->gt($existingLatestDate)) {
                                    if (!$latestNewStatement || $cutoffDate->gt($latestNewStatement->statement_date)) {
                                        $latestNewStatement = $statement;
                                    }
                                }
                            }

                            $firstMonth->addMonthNoOverflow();
                        }

                        $debt->update(['outstanding_amount' => $currentBalance]);

                        if ($latestNewStatement) {
                            $debt->update([
                                'statement_balance' => $latestNewStatement->closing_balance,
                                'due_date' => $latestNewStatement->due_date,
                                'minimum_payment' => '0.00',
                                'minimum_payment_confirmed' => false,
                            ]);
                            $card->update([
                                'statement_balance' => $latestNewStatement->closing_balance,
                                'next_due_date' => $latestNewStatement->due_date,
                                'amount' => bccomp((string) $latestNewStatement->closing_balance, '0.00', 2) > 0
                                    ? $latestNewStatement->closing_balance
                                    : '0.00',
                                'current_balance' => $currentBalance,
                            ]);
                        }
                    }, 3);
                }
            });

        $this->info('Credit card cut-offs processed successfully.');
        return self::SUCCESS;
    }

    private function syncPaymentOccurrence(Commitment $card, CreditCardStatement $statement): void
    {
        $occurrence = CommitmentOccurrence::query()
            ->where('commitment_id', $card->id)
            ->whereDate('due_date', $statement->due_date->toDateString())
            ->where('schedule_version', 1)
            ->lockForUpdate()
            ->first();

        if (!$occurrence && bccomp((string) $statement->closing_balance, '0.00', 2) > 0) {
            CommitmentOccurrence::query()->create([
                'user_id' => $card->user_id,
                'commitment_id' => $card->id,
                'amount_due' => $statement->closing_balance,
                'due_date' => $statement->due_date,
                'status' => 'scheduled',
                'schedule_version' => 1,
            ]);

            return;
        }

        if ($occurrence && !$occurrence->payments()->whereNull('reversed_at')->exists()) {
            $occurrence->update([
                'amount_due' => bccomp((string) $statement->closing_balance, '0.00', 2) > 0
                    ? $statement->closing_balance
                    : '0.00',
                'status' => 'scheduled',
            ]);
        }
    }
}
