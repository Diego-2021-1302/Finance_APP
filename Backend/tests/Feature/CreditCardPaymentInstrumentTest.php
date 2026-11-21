<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditCardPaymentInstrumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_paying_utility_commitment_with_credit_card_increases_card_debt_and_leaves_bank_cash_untouched(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // 1. Create a liquid bank account (Checking account with RD$10,000)
        $bankAccount = Account::create([
            'user_id' => $user->id,
            'name' => 'Cuenta Corriente BHD',
            'type' => 'checking',
            'currency' => 'DOP',
            'is_active' => true,
        ]);
        FinancialTransaction::create([
            'user_id' => $user->id,
            'account_id' => $bankAccount->id,
            'description' => 'Apertura',
            'amount' => '10000.00',
            'occurred_at' => now(),
            'kind' => 'account_opening',
        ]);

        // 2. Register Credit Card: Limit RD$50,000, Used RD$20,000
        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Visa Gold Popular',
            'type' => 'credit_card',
            'reference_number' => '4321',
            'institution' => 'Banco Popular',
            'current_balance' => '20000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'credit_limit' => '50000.00',
        ]);

        $cardAccount = Account::where('user_id', $user->id)->where('name', 'Visa Gold Popular')->firstOrFail();
        $cardDebt = Debt::where('user_id', $user->id)->where('name', 'Visa Gold Popular')->firstOrFail();

        // 3. Create Internet Commitment: RD$2,000
        $internet = Commitment::create([
            'user_id' => $user->id,
            'name' => 'Servicio de Internet Claro',
            'type' => 'utility',
            'amount' => '2000.00',
            'frequency' => 'monthly',
            'next_due_date' => now()->addDays(5)->toDateString(),
            'is_active' => true,
            'category' => 'Internet',
        ]);
        $occurrence = CommitmentOccurrence::create([
            'user_id' => $user->id,
            'commitment_id' => $internet->id,
            'amount_due' => '2000.00',
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => 'scheduled',
        ]);

        // 4. Pay Internet commitment (RD$2,000) USING the credit card account as payment instrument
        $response = $this->withToken($token)->postJson("/api/v1/commitments/{$internet->id}/occurrences/{$occurrence->id}/payments", [
            'amount' => '2000.00',
            'account_id' => $cardAccount->id,
            'paid_at' => now()->toDateString(),
            'idempotency_key' => '11111111-2222-3333-4444-555555555555',
        ]);

        $response->assertCreated();

        // Verification A: Internet occurrence status is 'paid'
        $occurrence->refresh();
        $this->assertEquals('paid', $occurrence->status);

        // Verification B: Credit Card debt increased from 20,000 to 22,000
        $cardDebt->refresh();
        $this->assertEquals('22000.00', (string) $cardDebt->outstanding_amount);

        // Verification C: DebtBalanceMovement created for credit card
        $this->assertDatabaseHas('debt_balance_movements', [
            'user_id' => $user->id,
            'debt_id' => $cardDebt->id,
            'account_id' => $cardAccount->id,
            'kind' => 'increase',
            'delta' => '2000.00',
        ]);

        // Verification D: Bank account liquid cash remained UNCHANGED at RD$10,000
        $bankCash = FinancialTransaction::where('account_id', $bankAccount->id)->sum('amount');
        $this->assertEquals('10000.00', (string) $bankCash);
    }

    public function test_paying_credit_card_debt_from_bank_account_reduces_bank_cash_and_recovers_credit(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // 1. Bank account with RD$10,000
        $bankAccount = Account::create([
            'user_id' => $user->id,
            'name' => 'Cuenta Ahorro BHD',
            'type' => 'savings',
            'currency' => 'DOP',
            'is_active' => true,
        ]);
        FinancialTransaction::create([
            'user_id' => $user->id,
            'account_id' => $bankAccount->id,
            'description' => 'Apertura',
            'amount' => '10000.00',
            'occurred_at' => now(),
            'kind' => 'account_opening',
        ]);

        // 2. Register Credit Card with RD$22,000 debt
        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Visa Platinum',
            'type' => 'credit_card',
            'reference_number' => '5555',
            'institution' => 'Banco Popular',
            'current_balance' => '22000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'credit_limit' => '50000.00',
        ]);

        $cardCommitment = Commitment::where('user_id', $user->id)->where('name', 'Visa Platinum')->firstOrFail();
        $cardOccurrence = CommitmentOccurrence::where('commitment_id', $cardCommitment->id)->firstOrFail();
        $cardDebt = Debt::where('user_id', $user->id)->where('name', 'Visa Platinum')->firstOrFail();

        // 3. User pays RD$5,000 of credit card from bank savings account
        $response = $this->withToken($token)->postJson("/api/v1/commitments/{$cardCommitment->id}/occurrences/{$cardOccurrence->id}/payments", [
            'amount' => '5000.00',
            'account_id' => $bankAccount->id,
            'paid_at' => now()->toDateString(),
            'idempotency_key' => '66666666-7777-8888-9999-000000000000',
        ]);

        $response->assertCreated();

        // Verification A: Bank account cash decreases by RD$5,000 (10,000 - 5,000 = 5,000)
        $bankCash = FinancialTransaction::where('account_id', $bankAccount->id)->sum('amount');
        $this->assertEquals('5000.00', (string) $bankCash);

        // Verification B: Credit Card debt decreases from 22,000 to 17,000
        $cardDebt->refresh();
        $this->assertEquals('17000.00', (string) $cardDebt->outstanding_amount);
    }

    public function test_loan_cannot_be_paid_from_credit_card_account(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $loan = Commitment::create([
            'user_id' => $user->id,
            'name' => 'Préstamo vehicular',
            'type' => 'loan',
            'reference_number' => 'PREST-100',
            'institution' => 'Banco Popular',
            'amount' => '5000.00',
            'frequency' => 'monthly',
            'next_due_date' => now()->toDateString(),
            'current_balance' => '50000.00',
            'interest_rate' => '12.000',
            'maturity_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);
        $occurrence = CommitmentOccurrence::create([
            'user_id' => $user->id,
            'commitment_id' => $loan->id,
            'amount_due' => '5000.00',
            'due_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);
        $card = Account::create([
            'user_id' => $user->id,
            'name' => 'Tarjeta origen',
            'type' => 'credit_card',
            'currency' => 'DOP',
            'is_active' => true,
        ]);

        $response = $this->withToken($token)->postJson("/api/v1/commitments/{$loan->id}/occurrences/{$occurrence->id}/payments", [
            'amount' => '5000.00',
            'account_id' => $card->id,
            'paid_at' => now()->toDateString(),
            'idempotency_key' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeef',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.details.account_id.0', 'Una tarjeta no puede utilizarse como origen para pagar un préstamo o otra tarjeta. Use una cuenta líquida compatible.');
    }

    public function test_payment_exceeding_credit_card_available_limit_fails_authorization(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Register Credit Card: Limit RD$50,000, Used RD$20,000 (Available: RD$30,000)
        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Visa Limit Test',
            'type' => 'credit_card',
            'reference_number' => '8888',
            'institution' => 'Banco Popular',
            'current_balance' => '20000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'credit_limit' => '50000.00',
        ]);

        $cardAccount = Account::where('user_id', $user->id)->where('name', 'Visa Limit Test')->firstOrFail();

        // Create Commitment for RD$35,000
        $largeCommitment = Commitment::create([
            'user_id' => $user->id,
            'name' => 'Compra Equipos',
            'type' => 'utility',
            'amount' => '35000.00',
            'frequency' => 'once',
            'next_due_date' => now()->addDays(2)->toDateString(),
            'is_active' => true,
        ]);
        $occurrence = CommitmentOccurrence::create([
            'user_id' => $user->id,
            'commitment_id' => $largeCommitment->id,
            'amount_due' => '35000.00',
            'due_date' => now()->addDays(2)->toDateString(),
            'status' => 'scheduled',
        ]);

        // Attempt to pay RD$35,000 with card that only has RD$30,000 available credit
        $response = $this->withToken($token)->postJson("/api/v1/commitments/{$largeCommitment->id}/occurrences/{$occurrence->id}/payments", [
            'amount' => '35000.00',
            'account_id' => $cardAccount->id,
            'paid_at' => now()->toDateString(),
            'idempotency_key' => '77777777-8888-9999-0000-111111111111',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('excede su límite de crédito disponible', $response->json('error.details.account_id.0'));
    }
}
