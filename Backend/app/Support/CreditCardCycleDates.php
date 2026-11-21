<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** Monthly statement cycles; due dates belong to the cutoff that generated them. */
final class CreditCardCycleDates
{
    public static function cutoffForMonth(Carbon $month, int $day): Carbon
    {
        $date = $month->copy()->startOfMonth();
        return $date->day(min(max(1, $day), $date->daysInMonth))->startOfDay();
    }

    public static function schedule(int $day, int $daysAfterCutoff, ?Carbon $reference = null): array
    {
        $today = ($reference ?? now())->copy()->startOfDay();
        $last = self::cutoffForMonth($today, $day);
        if ($last->gt($today)) {
            $last = self::cutoffForMonth($today->copy()->subMonthNoOverflow(), $day);
        }
        $next = self::cutoffForMonth($last->copy()->addMonthNoOverflow(), $day);
        $lastDue = $last->copy()->addDays($daysAfterCutoff);
        // If the previous bill's due date has passed, the next payable cycle is used.
        $payableDue = $lastDue->gte($today) ? $lastDue : $next->copy()->addDays($daysAfterCutoff);
        return [
            'last_cutoff' => $last,
            'next_cutoff' => $next,
            'last_due' => $lastDue,
            'next_cutoff_due' => $next->copy()->addDays($daysAfterCutoff),
            'next_due' => $payableDue,
        ];
    }
}
