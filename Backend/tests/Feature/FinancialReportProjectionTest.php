<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\FinancialTransaction;
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
}
