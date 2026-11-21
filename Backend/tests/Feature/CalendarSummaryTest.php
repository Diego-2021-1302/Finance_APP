<?php

namespace Tests\Feature;

use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\Reserve;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_get_monthly_obligations_summary(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('token')->plainTextToken;

        $commitment = Commitment::query()->create([
            'user_id' => $user->id,
            'name' => 'Luz',
            'amount' => '1500.00',
            'frequency' => 'monthly',
            'next_due_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        CommitmentOccurrence::query()->create([
            'user_id' => $user->id,
            'commitment_id' => $commitment->id,
            'amount_due' => '1500.00',
            'due_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        Reserve::query()->create([
            'user_id' => $user->id,
            'name' => 'Fondo Luz',
            'target_amount' => '1500.00',
            'current_amount' => '500.00',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/calendar/summary?year_month=' . now()->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('total_scheduled', '1500.00')
            ->assertJsonPath('total_pending', '1500.00')
            ->assertJsonPath('total_reserved', '500.00')
            ->assertJsonPath('total_uncovered', '1000.00');

        $calendar = $this->withToken($token)->getJson('/api/v1/calendar?from=' . now()->startOfMonth()->toDateString() . '&to=' . now()->endOfMonth()->toDateString());
        $calendar->assertOk()
            ->assertJsonFragment(['commitment_id' => $commitment->id]);
    }
}
