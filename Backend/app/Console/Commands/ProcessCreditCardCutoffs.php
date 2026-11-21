<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\CreditCardStatement;
use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use App\Services\DebtBalanceLedger;
use App\Support\CreditCardCycleDates;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                                // Fee is assessed once per statement cycle, before the closing balance is saved.
                                // Legacy entry-triggered fee modes are intentionally not charged again here.
                                $fee = $this->calculateCutoffOverdraftFee($card, $debt, $cutoffDate, $statementBalance);
                                if (bccomp($fee, '0.00', 2) > 0) {
                                    $eventKey = 'cutoff-overdraft:' . $card->id . ':' . $cutoffDate->toDateString();
                                    $exists = DebtBalanceMovement::query()
                                        ->where('debt_id', $debt->id)
                                        ->where('idempotency_key', $eventKey)
                                        ->exists();
                                    if (!$exists) {
                                        DebtBalanceMovement::query()->create([
                                            'user_id' => $card->user_id,
                                            'debt_id' => $debt->id,
                                            'account_id' => $account->id,
                                            'kind' => 'overdraft_fee',
                                            'delta' => $fee,
                                            'description' => 'Cargo por sobregiro al corte ' . $cutoffDate->toDateString(),
                                            'occurred_at' => $cutoffEnd,
                                            'idempotency_key' => $eventKey,
                                        ]);
                                        $statementBalance = bcadd($statementBalance, $fee, 2);
                                        $currentBalance = bcadd($currentBalance, $fee, 2);
                                    }
                                }
                                $dueDate = $cutoffDate->copy()->addDays((int) $card->payment_grace_days);
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

    /**
     * Calculates a fee from the bank conditions entered by the user.
     * cutoff_daily uses annual simple interest on the end-of-day overdraft
     * during the billing period (ACT/365), not on the authorized limit.
     */
    private function calculateCutoffOverdraftFee(Commitment $card, Debt $debt, Carbon $cutoff, string $closingBalance): string
    {
        $mode = (string) ($card->overdraft_fee_type ?? 'none');
        if (!in_array($mode, ['cutoff_fixed', 'cutoff_percentage', 'cutoff_both', 'cutoff_daily'], true)) {
            return '0.00';
        }
        if ($card->approved_credit_limit === null) {
            return '0.00';
        }
        $limit = (string) $card->approved_credit_limit;
        $fixed = (string) ($card->overdraft_fee_amount ?? '0.00');
        $rate = (string) ($card->overdraft_fee_percentage ?? '0.00');
        $excess = bcsub($closingBalance, $limit, 2);
        if ($mode !== 'cutoff_daily') {
            if (bccomp($excess, '0.00', 2) <= 0) {
                return '0.00';
            }
            $percentage = bcmul($excess, bcdiv($rate, '100', 8), 2);
            return match ($mode) {
                'cutoff_fixed' => $fixed,
                'cutoff_percentage' => $percentage,
                'cutoff_both' => bcadd($fixed, $percentage, 2),
            };
        }
        // Start after the previous monthly cutoff; do not charge prior days.
        $prior = $cutoff->copy()->subMonthNoOverflow()->startOfMonth();
        $prior->day(min((int) $card->statement_day, $prior->daysInMonth));
        $movements = DebtBalanceMovement::query()
            ->where('debt_id', $debt->id)
            ->where('occurred_at', '<=', $cutoff->copy()->endOfDay())
            ->orderBy('occurred_at')->orderBy('id')->get();
        $day = $prior->copy()->addDay()->startOfDay();
        $interest = '0.00000000';
        $balance = '0.00';
        $idx = 0;
        $count = $movements->count();
        while ($day->lte($cutoff)) {
            $end = $day->copy()->endOfDay();
            while ($idx < $count && $movements[$idx]->occurred_at->lte($end)) {
                $balance = bcadd($balance, (string) $movements[$idx]->delta, 2);
                $idx++;
            }
            $excessForDay = bcsub($balance, $limit, 2);
            if (bccomp($excessForDay, '0.00', 2) > 0) {
                $daily = bcdiv(bcmul($excessForDay, bcdiv($rate, '100', 8), 8), '365', 8);
                $interest = bcadd($interest, $daily, 8);
            }
            $day->addDay();
        }
        return number_format(round((float) $interest, 2), 2, '.', '');
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
