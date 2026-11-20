<?php

namespace Tests\Feature;

use App\Models\PiggyBankDeposit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PiggyBankTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_deposit_withdraw_and_query_piggy_bank(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // 1. Initial Deposit $25.00
        $depResponse = $this->withToken($token)->postJson('/api/v1/piggy-bank/deposits', [
            'amount' => '25.00',
            'type' => 'deposit',
            'note' => 'Primer ahorro',
        ]);

        $depResponse->assertCreated();
        $depResponse->assertJsonPath('data.new_balance', '25.00');

        // 2. Query Piggy Bank
        $indexResponse = $this->withToken($token)->getJson('/api/v1/piggy-bank');
        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('data.total_balance', '25.00');
        $indexResponse->assertJsonPath('data.monthly_summary.total_saved', '25.00');

        // 3. Partial Withdrawal $10.00
        $withResponse = $this->withToken($token)->postJson('/api/v1/piggy-bank/deposits', [
            'amount' => '10.00',
            'type' => 'withdrawal',
            'note' => 'Para compra menor',
        ]);
        $withResponse->assertCreated();
        $withResponse->assertJsonPath('data.new_balance', '15.00');

        // 4. Excessive Withdrawal attempt (Attempting $50 when balance is $15) -> 422
        $excessResponse = $this->withToken($token)->postJson('/api/v1/piggy-bank/deposits', [
            'amount' => '50.00',
            'type' => 'withdrawal',
        ]);
        $excessResponse->assertStatus(422);

        // 5. Reset / Empty Alcancía
        $resetResponse = $this->withToken($token)->postJson('/api/v1/piggy-bank/reset');
        $resetResponse->assertOk();
        $resetResponse->assertJsonPath('data.new_balance', '0.00');
    }
}
