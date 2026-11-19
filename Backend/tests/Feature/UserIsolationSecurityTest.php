<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserIsolationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_associate_another_users_commitment_in_reserve(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $token1 = $user1->createToken('token1')->plainTextToken;

        $commitment2 = Commitment::query()->create([
            'user_id' => $user2->id,
            'name' => 'User 2 Commitment',
            'type' => 'service',
            'amount' => '100.00',
            'frequency' => 'monthly',
            'next_due_date' => '2026-12-01',
            'is_active' => true,
        ]);

        $this->withToken($token1)->postJson('/api/v1/reserves', [
            'name' => 'Invalid Reserve',
            'target_amount' => '500.00',
            'commitment_id' => $commitment2->id,
        ])->assertUnprocessable();
    }
}
