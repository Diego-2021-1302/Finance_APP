<?php

namespace App\Console\Commands;

use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateCommitmentOccurrences extends Command
{
    protected $signature = 'finance:generate-occurrences';
    protected $description = 'Independently generate future commitment occurrences up to 60 days ahead';

    public function handle(): int
    {
        $horizon = now()->addDays(60);

        Commitment::query()->where('is_active', true)->chunkById(100, function ($commitments) use ($horizon) {
            foreach ($commitments as $commitment) {
                $latestOccurrence = CommitmentOccurrence::query()
                    ->where('commitment_id', $commitment->id)
                    ->orderByDesc('due_date')
                    ->first();

                $currentDueDate = $latestOccurrence
                    ? Carbon::parse($latestOccurrence->due_date)
                    : ($commitment->next_due_date ? Carbon::parse($commitment->next_due_date) : null);

                if (!$currentDueDate) {
                    continue;
                }

                $baseDay = $commitment->next_due_date
                    ? Carbon::parse($commitment->next_due_date)->day
                    : $currentDueDate->day;

                // If latest occurrence is in the past or today, start generating forward
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

                    $next = $this->calculateNextDueDate($currentDueDate, $commitment->frequency, $baseDay);
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

    private function calculateNextDueDate(Carbon $currentDueDate, string $frequency, int $baseDay): ?Carbon
    {
        if ($frequency === 'weekly') return $currentDueDate->copy()->addWeek();
        if ($frequency === 'biweekly') return $currentDueDate->copy()->addWeeks(2);

        $addMonths = match ($frequency) {
            'monthly' => 1,
            'quarterly' => 3,
            'yearly' => 12,
            default => 0,
        };
        if ($addMonths === 0) return null;

        $targetMonth = $currentDueDate->copy()->startOfMonth()->addMonths($addMonths);
        $daysInTarget = $targetMonth->daysInMonth;
        $dayToSet = min($baseDay, $daysInTarget);
        return $targetMonth->day($dayToSet);
    }
}
