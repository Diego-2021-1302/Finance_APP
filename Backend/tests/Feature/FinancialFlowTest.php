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
