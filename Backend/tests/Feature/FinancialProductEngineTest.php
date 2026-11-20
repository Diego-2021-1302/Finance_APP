<?php

namespace Tests\Feature;

use App\Models\Commitment;
use App\Models\Debt;
use App\Models\FinancialInstitution;
use App\Models\FinancialProduct;
use App\Models\FinancialProductVariant;
use App\Models\FinancialTariffRecord;
use App\Models\User;
use App\Services\FinancialProductEngine;
use Database\Seeders\FinancialCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialProductEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinancialCatalogSeeder::class);
    }

    public function test_resolves_conditions_following_priority_hierarchy_including_promo_rate(): void
    {
        $user = User::factory()->create();
        $engine = app(FinancialProductEngine::class);

        $bpd = FinancialInstitution::where('sb_code', '101')->firstOrFail();
        $product = FinancialProduct::where('financial_institution_id', $bpd->id)->where('name', 'Visa Gold Popular')->firstOrFail();
        $variant = FinancialProductVariant::where('financial_product_id', $product->id)->firstOrFail();

        // 1. Create card commitment with active promotional rate 12% until next month
        $commitment = Commitment::create([
            'user_id' => $user->id,
            'financial_institution_id' => $bpd->id,
            'financial_product_variant_id' => $variant->id,
            'name' => 'Visa Promo Test',
            'type' => 'credit_card',
            'amount' => '1000.00',
            'frequency' => 'monthly',
            'interest_rate' => '60.00',
            'promo_interest_rate' => '12.00',
            'promo_until_date' => now()->addDays(15)->toDateString(),
            'is_active' => true,
        ]);

        // Resolves promo rate while active
        $conditions = $engine->resolveApplicableConditions($commitment, now()->toDateString());
        $this->assertEquals(0, bccomp('12.00', $conditions['annual_interest_rate'], 2));
        $this->assertTrue($conditions['is_promotional']);
        $this->assertStringContainsString('Promoción activa', $conditions['explanation']['rate_source']);

        // Resolves regular contract rate when promo expires
        $expiredConditions = $engine->resolveApplicableConditions($commitment, now()->addMonth()->toDateString());
        $this->assertEquals(0, bccomp('60.00', $expiredConditions['annual_interest_rate'], 2));
        $this->assertFalse($expiredConditions['is_promotional']);
    }

    public function test_calculates_interest_and_french_amortization_schedule(): void
    {
        $engine = app(FinancialProductEngine::class);

        // Interest calculation
        $interestResult = $engine->calculateInterest('100000.00', '18.00', 'monthly');
        $this->assertEquals('1500.00', $interestResult['interest']);
        $this->assertEquals('contractual_calculable', $interestResult['classification']);

        // French Amortization (Loan $100,000, 18% annual, 12 months)
        $scheduleResult = $engine->calculateFrenchAmortization('100000.00', '18.00', 12);
        $this->assertEquals('contractual_calculable', $scheduleResult['classification']);
        $this->assertCount(12, $scheduleResult['schedule']);

        // Final month balance is 0.00
        $lastMonth = end($scheduleResult['schedule']);
        $this->assertEquals('0.00', $lastMonth['remaining_balance']);
    }

    public function test_calculates_late_fee_and_taxes_contractually(): void
    {
        $user = User::factory()->create();
        $engine = app(FinancialProductEngine::class);

        $debt = Debt::create([
            'user_id' => $user->id,
            'name' => 'Préstamo con Mora',
            'kind' => 'loan',
            'outstanding_amount' => '50000.00',
            'minimum_payment' => '5000.00',
            'late_fee_fixed' => '300.00',
            'late_fee_percentage' => '2.00',
            'overdraft_fee_tax_rate' => '18.00',
        ]);

        // Overdue $10,000 for 10 days
        // Fixed 300 + 2% of 10,000 = 200 => Total fee = 500.00 -> 18% Tax = 90.00 -> Total Charge = 590.00
        $lateFee = $engine->calculateLateFee('10000.00', 10, $debt);

        $this->assertEquals('500.00', $lateFee['fee']);
        $this->assertEquals('90.00', $lateFee['tax']);
        $this->assertEquals('590.00', $lateFee['total_charge']);
        $this->assertEquals('contractual_calculable', $lateFee['classification']);
    }

    public function test_amortization_uses_decimal_compounding_without_float(): void
    {
        $engine = app(FinancialProductEngine::class);
        $schedule = $engine->calculateFrenchAmortization('100000.00', '18.00', 12);

        $this->assertEquals('9168.00', $schedule['installment']);
        $this->assertCount(12, $schedule['schedule']);
        $this->assertEquals('0.00', end($schedule['schedule'])['remaining_balance']);
    }

    public function test_daily_balance_interest_and_payment_application_are_reproducible(): void
    {
        $engine = app(FinancialProductEngine::class);

        $interest = $engine->calculateDailyBalanceInterest('100000.00', '36.50', 30);
        $this->assertEquals('3000.00', $interest['interest']);

        $application = $engine->calculateCardPaymentApplication('500.00', [
            'moratorium' => '100.00',
            'late_fee' => '50.00',
            'advances' => '200.00',
            'purchases' => '1000.00',
            'principal' => '0.00',
        ], [
            'moratorium' => '18.00',
            'late_fee' => '18.00',
        ]);

        $this->assertEquals([
            'moratorium' => '100.00',
            'late_fee' => '50.00',
            'advances' => '200.00',
            'purchases' => '150.00',
            'principal' => '0.00',
        ], $application['applied']);
        $this->assertEquals('27.00', $application['tax_applied']);
        $this->assertEquals('0.00', $application['remaining_payment']);
    }

    public function test_money_rounding_is_half_up_and_not_float_based(): void
    {
        $engine = app(FinancialProductEngine::class);
        $daily = $engine->calculateDailyBalanceInterest('1000.00', '18.25', 1);

        $this->assertEquals('0.50', $daily['interest']);
    }
}
