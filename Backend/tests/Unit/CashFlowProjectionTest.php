<?php
namespace Tests\Unit;

use App\Services\CashFlowProjection;
use PHPUnit\Framework\TestCase;

class CashFlowProjectionTest extends TestCase
{
    public function test_detects_shortfall_before_income_even_with_positive_ending_balance(): void
    {
        $result = (new CashFlowProjection())->calculate('0.00', [
            ['name'=>'Loan','due_date'=>'2026-12-05','amount_due'=>'4500.00','occurrence_id'=>1],
            ['name'=>'Internet','due_date'=>'2026-12-10','amount_due'=>'2000.00','occurrence_id'=>2],
        ], [['source'=>'Salary','date'=>'2026-12-15','remaining'=>'10500.00']], '2026-12-01');
        self::assertSame('6500.00', $result['peak_shortfall']);
        self::assertSame('4000.00', $result['ending_balance']);
        self::assertSame('2026-12-05', $result['first_shortfall_date']);
    }
    public function test_same_day_payment_precedes_expected_income(): void
    {
        $result = (new CashFlowProjection())->calculate('0.10', [['name'=>'Bill','due_date'=>'2026-12-05','amount_due'=>'0.30','occurrence_id'=>1]], [['source'=>'Salary','date'=>'2026-12-05','remaining'=>'0.30']], '2026-12-01');
        self::assertSame('0.20', $result['peak_shortfall']);
        self::assertSame('0.10', $result['ending_balance']);
            self::assertTrue($result['events'][0]['timing_uncertain']);
            self::assertTrue($result['events'][1]['timing_uncertain']);
    }

    public function test_master_plan_cash_flow_scenario(): void
    {
        $result = (new CashFlowProjection())->calculate('0.00', [
            ['name'=>'Loan','due_date'=>'2026-12-05','amount_due'=>'4500.00','occurrence_id'=>1],
            ['name'=>'Internet','due_date'=>'2026-12-10','amount_due'=>'2000.00','occurrence_id'=>2],
            ['name'=>'Rent','due_date'=>'2026-12-18','amount_due'=>'6000.00','occurrence_id'=>3],
            ['name'=>'Phone','due_date'=>'2026-12-25','amount_due'=>'2000.00','occurrence_id'=>4],
        ], [
            ['source'=>'Salary','date'=>'2026-12-15','remaining'=>'10500.00'],
            ['source'=>'Salary','date'=>'2026-12-30','remaining'=>'10500.00'],
        ], '2026-12-01');

        self::assertSame('21000.00', $result['expected_total']);
        self::assertSame('14500.00', $result['commitments_total']);
        self::assertSame('6500.00', $result['ending_balance']);
        self::assertSame('6500.00', $result['peak_shortfall']);
        self::assertSame('2026-12-05', $result['first_shortfall_date']);
    }
}
