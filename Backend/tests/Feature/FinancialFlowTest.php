<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\Income;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
