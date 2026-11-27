<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class LoanPaymentSchedule
{
    public static function nextPaymentDate(string $recurrence, CarbonInterface $from): Carbon
    {
        $date = Carbon::instance($from->toDateTime())->startOfDay();

        if ($recurrence === 'biweekly') {
            return $date->addDays(15);
        }

        $day = $date->day;
        $nextMonth = $date->copy()->addMonthNoOverflow()->startOfMonth();
        return $nextMonth->day(min($day, $nextMonth->daysInMonth));
    }

    public static function remainingInstallments(
        CarbonInterface $from,
        CarbonInterface $maturityDate,
        string $recurrence,
        string $balance,
        string $installmentAmount
    ): array {
        $today = Carbon::instance($from->toDateTime())->startOfDay();
        $end = Carbon::instance($maturityDate->toDateTime())->startOfDay();

        if ($end->lt($today) || bccomp($balance, '0.00', 2) <= 0) {
            return ['count' => 0, 'months_remaining' => 0];
        }

        $count = 0;
        $cursor = $today->copy();
        while ($cursor->lte($end) && $count < 1200) {
            $count++;
            $cursor = $recurrence === 'biweekly'
                ? $cursor->copy()->addDays(15)
                : self::addMonthKeepingDay($cursor);
        }

        $monthsRemaining = (int) (($today->diffInMonths($end) % 1000));
        if ($end->day > $today->day) {
            $monthsRemaining++;
        }

        $balanceInstallments = bccomp($installmentAmount, '0.00', 2) > 0
            ? (int) ceil((float) bcdiv($balance, $installmentAmount, 8))
            : $count;

        return [
            'count' => min($count, $balanceInstallments),
            'months_remaining' => $monthsRemaining,
        ];
    }

    public static function addMonthKeepingDay(CarbonInterface $date): Carbon
    {
        $source = Carbon::instance($date->toDateTime());
        $day = $source->day;
        $next = $source->copy()->addMonthNoOverflow()->startOfMonth();
        return $next->day(min($day, $next->daysInMonth));
    }
}
