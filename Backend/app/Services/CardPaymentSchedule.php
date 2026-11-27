<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class CardPaymentSchedule
{
    public static function nextCutoff(int $statementDay, ?CarbonInterface $reference = null): Carbon
    {
        $today = $reference === null
            ? now()->copy()->startOfDay()
            : Carbon::instance($reference->toDateTime())->startOfDay();
        $cutoff = $today->copy()->day(min($statementDay, $today->daysInMonth));

        if ($cutoff->lt($today)) {
            $nextMonth = $today->copy()->addMonthNoOverflow()->startOfMonth();
            return $nextMonth->day(min($statementDay, $nextMonth->daysInMonth));
        }

        return $cutoff;
    }

    public static function paymentDue(int $statementDay, int $paymentGraceDays, ?CarbonInterface $reference = null): Carbon
    {
        return self::nextCutoff($statementDay, $reference)->copy()->addDays($paymentGraceDays);
    }

    public static function effectiveCutoff(?string $existingDueDate, int $statementDay, int $paymentGraceDays, ?CarbonInterface $reference = null): Carbon
    {
        $today = $reference === null
            ? now()->copy()->startOfDay()
            : Carbon::instance($reference->toDateTime())->startOfDay();

        if ($existingDueDate !== null) {
            $due = Carbon::parse($existingDueDate)->startOfDay();
            if ($due->gte($today)) {
                $cutoffFromDue = $due->copy()->subDays($paymentGraceDays);
                if ($cutoffFromDue->lte($today) || $cutoffFromDue->lt($today)) {
                    return $cutoffFromDue;
                }
            }
        }

        return self::nextCutoff($statementDay, $today);
    }

    public static function keepOrAdvanceDueDate(
        ?string $existingDueDate,
        int $statementDay,
        int $paymentGraceDays,
        ?CarbonInterface $reference = null
    ): Carbon {
        $today = $reference === null
            ? now()->copy()->startOfDay()
            : Carbon::instance($reference->toDateTime())->startOfDay();

        if ($existingDueDate !== null) {
            $due = Carbon::parse($existingDueDate)->startOfDay();
            if ($due->gte($today)) {
                return $due;
            }
        }

        return self::paymentDue($statementDay, $paymentGraceDays, $today);
    }
}
