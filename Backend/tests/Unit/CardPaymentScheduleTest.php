<?php

namespace Tests\Unit;

use App\Services\CardPaymentSchedule;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class CardPaymentScheduleTest extends TestCase
{
    public function test_uses_next_month_when_cutoff_day_passed(): void
    {
        $cutoff = CardPaymentSchedule::nextCutoff(7, Carbon::parse('2026-11-26')->startOfDay());
        $due = CardPaymentSchedule::paymentDue(7, 25, Carbon::parse('2026-11-26')->startOfDay());

        self::assertSame('2026-12-07', $cutoff->toDateString());
        self::assertSame('2027-01-01', $due->toDateString());
    }

    public function test_uses_current_month_when_cutoff_day_has_not_passed(): void
    {
        $cutoff = CardPaymentSchedule::nextCutoff(7, Carbon::parse('2026-11-01')->startOfDay());
        $due = CardPaymentSchedule::paymentDue(7, 25, Carbon::parse('2026-11-01')->startOfDay());

        self::assertSame('2026-11-07', $cutoff->toDateString());
        self::assertSame('2026-12-02', $due->toDateString());
    }

    public function test_keeps_due_date_when_cutoff_passed_but_due_date_is_still_future(): void
    {
        $due = CardPaymentSchedule::keepOrAdvanceDueDate(
            '2026-12-02',
            7,
            25,
            Carbon::parse('2026-11-26')->startOfDay()
        );

        self::assertSame('2026-12-02', $due->toDateString());
    }

    public function test_preserves_current_cutoff_until_due_date_passes(): void
    {
        $cutoff = CardPaymentSchedule::effectiveCutoff(
            '2026-12-02',
            7,
            25,
            Carbon::parse('2026-11-26')->startOfDay()
        );

        self::assertSame('2026-11-07', $cutoff->toDateString());
    }

    public function test_clamps_cutoff_day_in_short_month(): void
    {
        $cutoff = CardPaymentSchedule::nextCutoff(31, Carbon::parse('2027-01-31')->startOfDay());

        self::assertSame('2027-01-31', $cutoff->toDateString());

        $februaryCutoff = CardPaymentSchedule::nextCutoff(31, Carbon::parse('2027-02-01')->startOfDay());
        self::assertSame('2027-02-28', $februaryCutoff->toDateString());
    }
}
