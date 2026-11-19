<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\Reserve;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_excludes_credit_card_ledger_and_shows_all_remaining_due_amounts(): void
    {
        $user = User::factory()->create();
        $checking = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'Cuenta corriente',
            'type' => 'checking',
            'currency' => 'DOP',
            'is_active' => true,
        ]);
        $card = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'Tarjeta principal',
            'type' => 'credit_card',
            'currency' => 'DOP',
            'is_active' => true,
        ]);
        FinancialTransaction::query()->create([
            'user_id' => $user->id,
            'account_id' => $checking->id,
            'description' => 'Saldo inicial',
            'amount' => '10000.00',
            'occurred_at' => now(),
            'kind' => 'account_opening',
        ]);
        FinancialTransaction::query()->create([
            'user_id' => $user->id,
            'account_id' => $card->id,
            'description' => 'Compra con tarjeta',
            'amount' => '-3000.00',
            'occurred_at' => now(),
            'kind' => 'expense',
        ]);

        Reserve::query()->create([
            'user_id' => $user->id,
            'name' => 'Alquiler',
            'target_amount' => '3000.00',
            'current_amount' => '2000.00',
        ]);

        $commitment = Commitment::query()->create([
            'user_id' => $user->id,
            'name' => 'Servicio',
            'amount' => '100.00',
            'frequency' => 'monthly',
            'next_due_date' => now()->toDateString(),
            'is_active' => true,
        ]);
        $occurrences = collect(range(1, 7))->map(fn (int $day) => CommitmentOccurrence::query()->create([
            'user_id' => $user->id,
            'commitment_id' => $commitment->id,
            'amount_due' => '100.00',
            'due_date' => now()->addDays($day)->toDateString(),
            'status' => 'scheduled',
        ]));
        Payment::query()->create([
            'user_id' => $user->id,
            'commitment_occurrence_id' => $occurrences->first()->id,
            'account_id' => $checking->id,
            'amount' => '30.00',
            'paid_at' => now(),
            'idempotency_key' => '123e4567-e89b-12d3-a456-426614174004',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('balances.real', 'RD$ 10,000.00')
            ->assertJsonPath('balances.reserved', 'RD$ 2,000.00')
            ->assertJsonPath('balances.availableUncommitted', 'RD$ 8,000.00')
            ->assertJsonPath('balances.confirmed', 'RD$ 10,000.00')
            ->assertJsonPath('balances.accountCount', 1)
            ->assertJsonPath('commitments.dueSoon', 'RD$ 670.00')
            ->assertJsonCount(5, 'upcoming')
            ->assertJsonPath('upcoming.0.amount', 'RD$ 70.00');
    }
}
