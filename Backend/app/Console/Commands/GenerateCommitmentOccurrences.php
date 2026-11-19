<?php

namespace App\Console\Commands;

use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Services\CommitmentRecurrence;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateCommitmentOccurrences extends Command
{
    protected $signature = 'finance:generate-occurrences';
    protected $description = 'Independently generate future commitment occurrences up to 60 days ahead';

    public function handle(CommitmentRecurrence $recurrence): int
    {
        $horizon = now()->addDays(60);

        Commitment::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('type')->orWhere('type', '!=', 'credit_card');
            })
            ->chunkById(100, function ($commitments) use ($horizon, $recurrence) {
                foreach ($commitments as $commitment) {
                    $latestOccurrence = CommitmentOccurrence::query()
                        ->where('commitment_id', $commitment->id)
                        ->orderByDesc('due_date')
                        ->first();

                    $firstOccurrenceDate = CommitmentOccurrence::query()
                        ->where('commitment_id', $commitment->id)
                        ->orderBy('due_date')
                        ->orderBy('id')
                        ->value('due_date');
                    $currentDueDate = $latestOccurrence
                        ? Carbon::parse($latestOccurrence->due_date)
                        : ($commitment->next_due_date ? Carbon::parse($commitment->next_due_date) : null);

                    if (!$currentDueDate) {
                        continue;
                    }

                    $baseDay = Carbon::parse($firstOccurrenceDate ?? $currentDueDate)->day;

                    while ($currentDueDate->lte($horizon)) {
                        if ($commitment->maturity_date && $currentDueDate->gt(Carbon::parse($commitment->maturity_date))) {
                            break;
                        }

                        CommitmentOccurrence::query()->firstOrCreate(
                            [
                                'commitment_id' => $commitment->id,
                                'due_date' => $currentDueDate->toDateString(),
                                'schedule_version' => 1,
                            ],
                            [
                                'user_id' => $commitment->user_id,
                                'amount_due' => $commitment->amount,
                                'status' => 'scheduled',
                            ]
                        );

                        $next = $recurrence->nextDueDate($currentDueDate, $commitment->frequency, $baseDay);
                        if (!$next || $next->equalTo($currentDueDate)) {
                            break;
                        }
                        $currentDueDate = $next;
                    }
                }
            });

        $this->info('Commitment occurrences generated successfully.');
        return self::SUCCESS;
    }
}
