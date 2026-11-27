<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\FinancialTransaction;
use App\Models\Income;
use App\Models\Debt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReportProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_get_liquidity_projection_and_deficit_detection(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('token')->plainTextToken;
        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'DOP',
        ]);

        FinancialTransaction::query()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'description' => 'Opening',
            'amount' => '100.00',
            'occurred_at' => now(),
            'kind' => 'account_opening',
        ]);

        $commitment = Commitment::query()->create([
            'user_id' => $user->id,
            'name' => 'Big Bill',
            'amount' => '500.00',
            'frequency' => 'monthly',
            'next_due_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        CommitmentOccurrence::query()->create([
            'user_id' => $user->id,
            'commitment_id' => $commitment->id,
            'amount_due' => '500.00',
            'due_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/reports/projection')
            ->assertOk()
            ->assertJsonPath('initial_balance', '100.00')
            ->assertJsonPath('deficit_risk_date', now()->toDateString());
    }

    public function test_capture_scenario_assigns_future_income_and_reports_exact_ending_balance(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('token')->plainTextToken;
        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'DOP',
        ]);
        FinancialTransaction::query()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'description' => 'Opening',
            'amount' => '81.28',
            'occurred_at' => now(),
            'kind' => 'account_opening',
        ]);
        Income::query()->create([
            'user_id' => $user->id,
            'source' => 'Nómina',
            'amount' => '13225.00',
            'expected_date' => now()->addDays(10)->toDateString(),
        ]);

        foreach ([['Electricidad', '1700.00', 12], ['Internet', '1350.00', 15]] as [$name, $amount, $days]) {
            $commitment = Commitment::query()->create([
                'user_id' => $user->id,
                'name' => $name,
                'amount' => $amount,
                'frequency' => 'once',
                'next_due_date' => now()->addDays($days)->toDateString(),
                'is_active' => true,
            ]);
            CommitmentOccurrence::query()->create([
                'user_id' => $user->id,
                'commitment_id' => $commitment->id,
                'amount_due' => $amount,
                'due_date' => now()->addDays($days)->toDateString(),
                'status' => 'scheduled',
            ]);
        }

        $this->withToken($token)
            ->getJson('/api/v1/financial-plan?days=60')
            ->assertOk()
            ->assertJsonPath('data.summary.pending_obligations', '3050.00')
            ->assertJsonPath('data.summary.projected_closing_balance', '10256.28')
            ->assertJsonPath('data.summary.maximum_shortfall', '0.00')
            ->assertJsonPath('data.allocations.0.status', 'projected');
    }

    public function test_standalone_loan_with_no_commitment_is_normalized_as_monthly_obligation(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('token')->plainTextToken;
        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'DOP',
        ]);
        FinancialTransaction::query()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'description' => 'Opening',
            'amount' => '10000.00',
            'occurred_at' => now(),
            'kind' => 'account_opening',
        ]);
        Debt::query()->create([
            'user_id' => $user->id,
            'name' => 'Préstamo vehículo',
            'kind' => 'loan',
            'outstanding_amount' => '100000.00',
            'minimum_payment' => '5000.00',
            'minimum_payment_confirmed' => true,
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/financial-plan?days=30')
            ->assertOk()
            ->assertJsonPath('data.summary.pending_obligations', '5000.00')
            ->assertJsonPath('data.obligations.0.amount_original', '100000.00')
            ->assertJsonPath('data.obligations.0.amount_pending', '5000.00');
    }
}
