<?php

namespace Tests\Feature;

use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_get_prioritized_payment_agenda(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('token')->plainTextToken;

        $commitment = Commitment::query()->create([
            'user_id' => $user->id,
            'name' => 'Préstamo',
            'amount' => '1000.00',
            'frequency' => 'monthly',
            'next_due_date' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        CommitmentOccurrence::query()->create([
            'user_id' => $user->id,
            'commitment_id' => $commitment->id,
            'amount_due' => '1000.00',
            'due_date' => now()->subDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/priorities')
            ->assertOk()
            ->assertJsonPath('data.0.urgency', 'critical')
            ->assertJsonPath('data.0.name', 'Préstamo');
    }
}
