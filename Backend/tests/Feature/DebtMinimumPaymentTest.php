<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DebtMinimumPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_minimum_is_not_presented_as_confirmed_and_owner_can_record_bank_value(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;
        $response = $this->withToken($token)->postJson('/api/v1/debts', [
            'name' => 'Tarjeta principal',
            'kind' => 'credit_card',
            'outstanding_amount' => '5000.00',
            'due_date' => now()->addDays(3)->toDateString(),
            'idempotency_key' => '123e4567-e89b-12d3-a456-426614174005',
        ])->assertCreated()
            ->assertJsonPath('data.minimum', null)
            ->assertJsonPath('data.minimum_confirmed', false);

        $debtId = $response->json('data.id');
        $from = now()->toDateString();
        $to = now()->addDays(5)->toDateString();
        $this->withToken($token)->getJson("/api/v1/calendar?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('events.0.amount', null);

        $this->withToken($token)->patchJson("/api/v1/debts/{$debtId}/minimum-payment", [
            'minimum_payment' => '275.50',
        ])->assertOk()
            ->assertJsonPath('data.minimum', '275.50')
            ->assertJsonPath('data.minimum_confirmed', true);

        $this->withToken($token)->getJson("/api/v1/reports/cash-flow?from={$from}&to={$to}&group_by=month")
            ->assertOk()
            ->assertJsonPath('totals.forecast', '-275.50');

        $otherUser = User::factory()->create();
        $this->actingAs($otherUser, 'sanctum')->patchJson("/api/v1/debts/{$debtId}/minimum-payment", [
            'minimum_payment' => '100.00',
        ])->assertNotFound();
    }

    public function test_credit_card_commitment_accepts_only_an_explicit_bank_minimum(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Visa principal',
            'type' => 'credit_card',
            'reference_number' => '1234',
            'institution' => 'Banco Popular',
            'current_balance' => '5000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'minimum_payment' => '325.00',
        ])->assertCreated();

        $this->assertDatabaseHas('debts', [
            'user_id' => $user->id,
            'name' => 'Visa principal',
            'minimum_payment' => '325.00',
            'minimum_payment_confirmed' => true,
        ]);
    }

    public function test_creating_card_commitment_without_minimum_preserves_existing_confirmation(): void
    {
        $user = User::factory()->create();
        \App\Models\Debt::query()->create([
            'user_id' => $user->id,
            'name' => 'Visa guardada',
            'kind' => 'credit_card',
            'outstanding_amount' => '4000.00',
            'minimum_payment' => '180.00',
            'minimum_payment_confirmed' => true,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Visa guardada',
            'type' => 'credit_card',
            'reference_number' => '4567',
            'institution' => 'Banco BHD',
            'current_balance' => '4000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
        ])->assertCreated();

        $this->assertDatabaseHas('debts', [
            'user_id' => $user->id,
            'name' => 'Visa guardada',
            'minimum_payment' => '180.00',
            'minimum_payment_confirmed' => true,
        ]);
    }

    public function test_new_statement_cutoff_invalidates_the_previous_minimum(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-16 08:00:00'));

        try {
            $user = User::factory()->create();
            Account::query()->create([
                'user_id' => $user->id,
                'name' => 'Mastercard',
                'type' => 'credit_card',
                'currency' => 'DOP',
                'is_active' => true,
            ]);
            $commitment = \App\Models\Commitment::query()->create([
                'user_id' => $user->id,
                'name' => 'Mastercard',
                'type' => 'credit_card',
                'amount' => '2000.00',
                'frequency' => 'monthly',
                'next_due_date' => '2026-02-05',
                'is_active' => true,
                'current_balance' => '2000.00',
                'opening_balance' => '2000.00',
                'statement_day' => 15,
                'payment_grace_days' => 21,
                'statement_balance' => '1800.00',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]);
            $debt = \App\Models\Debt::query()->create([
                'user_id' => $user->id,
                'name' => $commitment->name,
                'kind' => 'credit_card',
                'outstanding_amount' => '2000.00',
                'minimum_payment' => '90.00',
                'minimum_payment_confirmed' => true,
            ]);
            \App\Models\DebtBalanceMovement::query()->create([
                'user_id' => $user->id,
                'debt_id' => $debt->id,
                'kind' => 'opening_balance',
                'delta' => '2000.00',
                'description' => 'Opening balance',
                'occurred_at' => '2026-01-01 00:00:00',
                'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
            ]);

            $this->artisan('finance:process-card-cutoffs')->assertSuccessful();

            $this->assertDatabaseHas('debts', [
                'user_id' => $user->id,
                'name' => 'Mastercard',
                'minimum_payment' => '0.00',
                'minimum_payment_confirmed' => false,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
