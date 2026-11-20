<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\Debt;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditCardArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_card_commitment_creates_linked_account_and_debt_with_explicit_foreign_keys(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Visa Gold Popular',
            'type' => 'credit_card',
            'reference_number' => '9876',
            'institution' => 'Banco Popular',
            'current_balance' => '15000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'statement_balance' => '15000.00',
            'minimum_payment' => '1500.00',
        ]);

        $response->assertCreated();

        $commitment = Commitment::where('user_id', $user->id)->where('name', 'Visa Gold Popular')->firstOrFail();
        $account = Account::where('user_id', $user->id)->where('name', 'Visa Gold Popular')->firstOrFail();
        $debt = Debt::where('user_id', $user->id)->where('name', 'Visa Gold Popular')->firstOrFail();

        // Assert explicit foreign keys link all three entities
        $this->assertEquals($account->id, $commitment->account_id);
        $this->assertEquals($debt->id, $commitment->debt_id);
        $this->assertEquals($commitment->id, $debt->commitment_id);
        $this->assertEquals($account->id, $debt->account_id);
        $this->assertEquals($commitment->id, $account->commitment_id);
        $this->assertEquals($debt->id, $account->debt_id);

        // Verify credit card account appears in accounts listing as payment instrument
        $accResponse = $this->withToken($token)->getJson('/api/v1/accounts');
        $accResponse->assertOk();
        $accList = collect($accResponse->json('data'));
        $this->assertTrue($accList->contains('id', (string) $account->id));
        $this->assertEquals('credit_card', $account->type);
    }

    public function test_expense_with_credit_card_increases_debt_and_does_not_create_duplicate_debt(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Register card
        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Mastercard BHD',
            'type' => 'credit_card',
            'reference_number' => '1234',
            'institution' => 'Banco BHD',
            'current_balance' => '5000.00',
            'statement_day' => 10,
            'payment_grace_days' => 20,
            'approved_credit_limit' => '50000.00',
            'authorized_overdraft_limit' => '10000.00',
            'limit_status' => 'confirmed',
        ]);

        $account = Account::where('user_id', $user->id)->where('name', 'Mastercard BHD')->firstOrFail();
        $debt = Debt::where('user_id', $user->id)->where('name', 'Mastercard BHD')->firstOrFail();

        // Register expense using credit card account
        $expenseResponse = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Alimentación',
            'amount' => '2500.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Supermercado Bravo',
            'idempotency_key' => '223e4567-e89b-12d3-a456-426614174999',
        ]);

        $expenseResponse->assertCreated();

        // Assert debt was updated (5000 + 2500 = 7500)
        $debt->refresh();
        $this->assertEquals('7500.00', (string) $debt->outstanding_amount);

        // Assert no duplicate debts were created for user
        $this->assertEquals(1, Debt::where('user_id', $user->id)->count());
    }
}
