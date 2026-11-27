<?php

namespace Tests\Unit;

use App\Services\LoanPaymentSchedule;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class LoanPaymentScheduleTest extends TestCase
{
    public function test_monthly_payment_moves_to_same_day_next_month(): void
    {
        $next = LoanPaymentSchedule::nextPaymentDate('monthly', Carbon::parse('2026-10-15')->startOfDay());

        self::assertSame('2026-11-15', $next->toDateString());
    }

    public function test_biweekly_payment_advances_15_days(): void
    {
        $next = LoanPaymentSchedule::nextPaymentDate('biweekly', Carbon::parse('2026-10-15')->startOfDay());

        self::assertSame('2026-10-30', $next->toDateString());
    }

    public function test_remaining_monthly_installments_uses_balance_and_fixed_quota(): void
    {
        $schedule = LoanPaymentSchedule::remainingInstallments(
            Carbon::parse('2026-11-26')->startOfDay(),
            Carbon::parse('2027-12-31')->startOfDay(),
            'monthly',
            '24000.00',
            '2000.00'
        );

        self::assertSame(12, $schedule['count']);
    }
}
