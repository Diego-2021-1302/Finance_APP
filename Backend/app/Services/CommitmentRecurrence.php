<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class CommitmentRecurrence
{
    public function nextDueDate(Carbon $currentDueDate, string $frequency, int $baseDay): ?Carbon
    {
        if ($frequency === 'weekly') {
            return $currentDueDate->copy()->addWeek();
        }

        if ($frequency === 'biweekly') {
            return $currentDueDate->copy()->addWeeks(2);
        }

        $months = match ($frequency) {
            'monthly' => 1,
            'quarterly' => 3,
            'yearly' => 12,
            default => 0,
        };

        if ($months === 0) {
            return null;
        }

        $targetMonth = $currentDueDate->copy()->startOfMonth()->addMonths($months);

        return $targetMonth->day(min(max(1, $baseDay), $targetMonth->daysInMonth));
    }
}
