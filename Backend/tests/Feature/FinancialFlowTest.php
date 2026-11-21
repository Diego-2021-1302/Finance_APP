<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\Income;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinancialFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_create_account_and_income_receipt(): void
    {
        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Juan Perez',
            'email' => 'juan@example.com',
            'password' => 'SuperSecretPassword123!',
            'password_confirmation' => 'SuperSecretPassword123!',
        ]);

        $registerResponse->assertCreated();

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'juan@example.com',
            'password' => 'SuperSecretPassword123!',
            'device_id' => '123e4567-e89b-12d3-a456-426614174000',
        ]);

        $loginResponse->assertOk();
        $token = $loginResponse->json('data.token');

        // Create account
        $accountResponse = $this->withToken($token)->postJson('/api/v1/accounts', [
            'name' => 'Cuenta de Ahorros',
            'institution' => 'Banco Popular',
            'type' => 'checking',
            'currency' => 'DOP',
            'opening_balance' => '10000.00',
        ]);

        $accountResponse->assertCreated();
        $accountId = $accountResponse->json('data.id');

        // Create income
        $incomeResponse = $this->withToken($token)->postJson('/api/v1/incomes', [
            'source' => 'Salario Mensual',
            'amount' => '25000.00',
            'expected_date' => now()->toDateString(),
        ]);

        $incomeResponse->assertCreated();
        $incomeId = $incomeResponse->json('data.id');

        // Record income receipt
        $receiptResponse = $this->withToken($token)->postJson("/api/v1/incomes/{$incomeId}/receipts", [
            'account_id' => $accountId,
            'amount' => '25000.00',
            'received_at' => now()->toDateTimeString(),
            'idempotency_key' => '123e4567-e89b-12d3-a456-426614174001',
        ]);

        $receiptResponse->assertCreated();
        $this->assertEquals('25000.00', $receiptResponse->json('data.amount'));
    }

    public function test_commitment_list_returns_unique_active_inactive_and_filtered_records(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        Commitment::query()->create([
            'user_id' => $user->id,
            'name' => 'Internet Hogar',
            'type' => 'utility',
            'institution' => 'Claro',
            'amount' => '2450.00',
            'frequency' => 'monthly',
            'next_due_date' => now()->addDays(3)->toDateString(),
            'is_active' => true,
        ]);
        Commitment::query()->create([
            'user_id' => $user->id,
            'name' => 'Seguro archivado',
            'type' => 'insurance',
            'institution' => 'ARS Universal',
            'amount' => '6800.00',
            'frequency' => 'monthly',
            'next_due_date' => now()->subMonth()->toDateString(),
            'is_active' => false,
        ]);

        $active = $this->withToken($token)->getJson('/api/v1/commitments?status=active&type=utility&search=internet');
        $active->assertOk()
            ->assertJsonCount(1, 'commitments')
            ->assertJsonPath('commitments.0.name', 'Internet Hogar')
            ->assertJsonPath('commitments.0.is_active', true)
            ->assertJsonStructure(['summary', 'meta']);

        $inactive = $this->withToken($token)->getJson('/api/v1/commitments?status=inactive');
        $inactive->assertOk()
            ->assertJsonCount(1, 'commitments')
            ->assertJsonPath('commitments.0.name', 'Seguro archivado')
            ->assertJsonPath('commitments.0.is_active', false);
    }

    public function test_card_unknown_limit_remains_null_during_create_and_update(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $create = $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Tarjeta sin verificar',
            'type' => 'credit_card',
            'reference_number' => '4321',
            'institution' => 'Banco Popular',
            'current_balance' => '12500.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => null,
            'authorized_overdraft_limit' => null,
            'limit_status' => 'unknown',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.approved_credit_limit', null)
            ->assertJsonPath('data.limit_status', 'unknown');

        $commitmentId = $create->json('data.id');
        $update = $this->withToken($token)->putJson("/api/v1/commitments/{$commitmentId}", [
            'name' => 'Tarjeta sin verificar actualizada',
            'type' => 'credit_card',
            'reference_number' => '4321',
            'institution' => 'Banco Popular',
            'current_balance' => '12500.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => null,
            'authorized_overdraft_limit' => null,
            'limit_status' => 'unknown',
        ]);

        $update->assertOk()
            ->assertJsonPath('data.approved_credit_limit', null)
            ->assertJsonPath('data.authorized_overdraft_limit', '0.00')
            ->assertJsonPath('data.limit_status', 'unknown');
    }

    public function test_manual_credit_card_stores_complete_manual_product_details(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Visa Manual',
            'type' => 'credit_card',
            'reference_number' => '4321',
            'institution' => 'Banco Popular',
            'current_balance' => '12500.00',
            'statement_balance' => '12500.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => '50000.00',
            'authorized_overdraft_limit' => '10000.00',
            'limit_status' => 'confirmed',
            'card_product_name' => 'Visa Gold',
            'card_variant' => 'Gold',
            'card_network' => 'Visa',
            'card_currency' => 'DOP',
            'issued_at' => '2026-01-15',
            'annual_fee' => '8500.00',
            'replacement_fee' => '600.00',
            'cash_advance_fee' => '1000.00',
            'late_fee' => '700.00',
            'card_benefits' => ['Seguro de viaje', 'Acceso a promociones'],
            'card_payment_methods' => ['Cuenta Popular', 'Banca móvil', 'Caja'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.card_product_name', 'Visa Gold')
            ->assertJsonPath('data.card_variant', 'Gold')
            ->assertJsonPath('data.card_network', 'Visa')
            ->assertJsonPath('data.card_currency', 'DOP')
            ->assertJsonPath('data.annual_fee', '8500.00')
            ->assertJsonPath('data.card_benefits.0', 'Seguro de viaje');

        $commitment = \App\Models\Commitment::where('user_id', $user->id)->where('name', 'Visa Manual')->firstOrFail();
        $this->assertSame('Visa Gold', $commitment->card_product_name);
        $this->assertSame(['Seguro de viaje', 'Acceso a promociones'], $commitment->card_benefits);
        $this->assertSame(['Cuenta Popular', 'Banca móvil', 'Caja'], $commitment->card_payment_methods);

        $this->assertSame('Visa Gold', $commitment->account?->card_product_name);
        $this->assertSame('Visa Gold', $commitment->debt?->card_product_name);
    }

    public function test_loan_commitment_detail_exposes_contractual_amortization(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $commitment = Commitment::query()->create([
            'user_id' => $user->id,
            'name' => 'Préstamo contractual',
            'type' => 'loan',
            'reference_number' => 'PREST-001',
            'institution' => 'Banco Popular',
            'amount' => '5000.00',
            'frequency' => 'monthly',
            'next_due_date' => now()->addMonth()->toDateString(),
            'opening_balance' => '50000.00',
            'current_balance' => '50000.00',
            'interest_rate' => '12.000',
            'maturity_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/commitments');
        $response->assertOk()
            ->assertJsonPath('commitments.0.id', $commitment->id)
            ->assertJsonPath('commitments.0.contract_conditions.amortization_type', 'french')
            ->assertJsonPath('commitments.0.contract_conditions.term_months', 12)
            ->assertJsonStructure(['commitments' => [['amortization' => ['installment', 'schedule']]]]);

        $this->assertNotEmpty($response->json('commitments.0.amortization.schedule'));
    }

    public function test_commitment_can_pause_resume_without_duplicate_or_historical_loss(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $commitment = Commitment::query()->create([
            'user_id' => $user->id,
            'name' => 'Internet mensual',
            'type' => 'utility',
            'amount' => '2450.00',
            'frequency' => 'monthly',
            'next_due_date' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);
        CommitmentOccurrence::query()->create([
            'user_id' => $user->id,
            'commitment_id' => $commitment->id,
            'amount_due' => '2450.00',
            'due_date' => now()->subMonth()->toDateString(),
            'status' => 'paid',
        ]);
        CommitmentOccurrence::query()->create([
            'user_id' => $user->id,
            'commitment_id' => $commitment->id,
            'amount_due' => '2450.00',
            'due_date' => now()->addMonth()->toDateString(),
            'status' => 'scheduled',
        ]);

        $pause = $this->withToken($token)->postJson("/api/v1/commitments/{$commitment->id}/pause");
        $pause->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertDatabaseCount('commitment_occurrences', 1);
        $this->assertDatabaseHas('commitment_occurrences', [
            'commitment_id' => $commitment->id,
            'status' => 'paid',
        ]);

        $resume = $this->withToken($token)->postJson("/api/v1/commitments/{$commitment->id}/resume");
        $resume->assertOk()->assertJsonPath('data.is_active', true);

        $this->assertDatabaseCount('commitment_occurrences', 2);
        $this->assertDatabaseCount('commitment_occurrences', 2);
    }

    public function test_commitment_recurrence_generation_and_partial_payment(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'Cuenta Corriente',
            'type' => 'checking',
            'currency' => 'DOP',
            'is_active' => true,
        ]);

        $commitment = Commitment::query()->create([
            'user_id' => $user->id,
            'name' => 'Internet',
            'amount' => '2000.00',
            'frequency' => 'monthly',
            'next_due_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $occurrence = CommitmentOccurrence::query()->create([
            'user_id' => $user->id,
            'commitment_id' => $commitment->id,
            'amount_due' => '2000.00',
            'due_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        // Run occurrence generator command
        $this->artisan('finance:generate-occurrences')->assertSuccessful();

        // Make partial payment of 500
        $token = $user->createToken('test-token')->plainTextToken;

        $paymentResponse = $this->withToken($token)->postJson("/api/v1/commitments/{$commitment->id}/occurrences/{$occurrence->id}/payments", [
            'account_id' => $account->id,
            'amount' => '500.00',
            'paid_at' => now()->toDateTimeString(),
            'idempotency_key' => '123e4567-e89b-12d3-a456-426614174002',
        ]);

        $paymentResponse->assertCreated();
        $this->assertEquals('1500.00', $paymentResponse->json('remaining_due'));
    }

    public function test_loan_commitment_requires_contract_details(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Préstamo personal',
            'type' => 'loan',
            'amount' => '5000.00',
            'frequency' => 'monthly',
            'next_due_date' => now()->toDateString(),
        ]);
        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.details.reference_number.0', 'validation.required')
            ->assertJsonPath('error.details.institution.0', 'validation.required')
            ->assertJsonPath('error.details.current_balance.0', 'validation.required')
            ->assertJsonPath('error.details.interest_rate.0', 'validation.required')
            ->assertJsonPath('error.details.maturity_date.0', 'validation.required');
    }

    public function test_full_payment_leaves_occurrence_generation_to_scheduler_and_preserves_month_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 12:00:00'));

        try {
            $user = User::factory()->create();
            $account = Account::query()->create([
                'user_id' => $user->id,
                'name' => 'Cuenta corriente',
                'type' => 'checking',
                'currency' => 'DOP',
                'is_active' => true,
            ]);
            $commitment = Commitment::query()->create([
                'user_id' => $user->id,
                'name' => 'Factura mensual',
                'type' => 'utility',
                'amount' => '1000.00',
                'frequency' => 'monthly',
                'next_due_date' => '2026-01-31',
                'is_active' => true,
            ]);
            $occurrence = CommitmentOccurrence::query()->create([
                'user_id' => $user->id,
                'commitment_id' => $commitment->id,
                'amount_due' => '1000.00',
                'due_date' => '2026-01-31',
                'status' => 'scheduled',
            ]);
            $token = $user->createToken('test-token')->plainTextToken;

            $this->withToken($token)->postJson("/api/v1/commitments/{$commitment->id}/occurrences/{$occurrence->id}/payments", [
                'account_id' => $account->id,
                'amount' => '1000.00',
                'paid_at' => now()->toDateTimeString(),
                'idempotency_key' => '123e4567-e89b-12d3-a456-426614174003',
            ])->assertCreated()
                ->assertJsonPath('remaining_due', '0.00');

            $this->assertDatabaseCount('commitment_occurrences', 1);

            $this->artisan('finance:generate-occurrences')->assertSuccessful();

            $dates = CommitmentOccurrence::query()
                ->where('commitment_id', $commitment->id)
                ->orderBy('due_date')
                ->pluck('due_date')
                ->map(fn ($date) => $date->toDateString())
                ->all();

            $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $dates);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_calendar_and_cash_flow_share_month_end_recurrence_dates(): void
    {
        $user = User::factory()->create();
        $commitment = Commitment::query()->create([
            'user_id' => $user->id,
            'name' => 'Factura mensual',
            'type' => 'utility',
            'amount' => '2000.00',
            'frequency' => 'monthly',
            'next_due_date' => '2026-01-31',
            'is_active' => true,
        ]);
        CommitmentOccurrence::query()->create([
            'user_id' => $user->id,
            'commitment_id' => $commitment->id,
            'amount_due' => '2000.00',
            'due_date' => '2026-01-31',
            'status' => 'paid',
        ]);
        CommitmentOccurrence::query()->create([
            'user_id' => $user->id,
            'commitment_id' => $commitment->id,
            'amount_due' => '1234.00',
            'due_date' => '2026-02-28',
            'status' => 'scheduled',
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $calendarEvents = $this->withToken($token)
            ->getJson('/api/v1/calendar?from=2026-02-01&to=2026-03-31')
            ->assertOk()
            ->json('events');

        $this->assertSame(['2026-02-28', '2026-03-31'], array_column($calendarEvents, 'date'));
        $this->assertSame(['1234.00', '2000.00'], array_column($calendarEvents, 'amount'));

        $this->withToken($token)
            ->getJson('/api/v1/reports/cash-flow?from=2026-02-01&to=2026-03-31')
            ->assertOk()
            ->assertJsonPath('totals.forecast', '-3234.00');
    }
}
