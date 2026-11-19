<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_savings_checking_and_cash_accounts(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // 1. Savings
        $this->withToken($token)->postJson('/api/v1/accounts', [
            'name' => 'Cuenta Ahorro Popular',
            'type' => 'savings',
            'currency' => 'DOP',
            'opening_balance' => '15000.00',
        ])->assertCreated();

        // 2. Checking
        $this->withToken($token)->postJson('/api/v1/accounts', [
            'name' => 'Cuenta Corriente BHD',
            'type' => 'checking',
            'currency' => 'DOP',
            'opening_balance' => '5000.00',
        ])->assertCreated();

        // 3. Cash
        $this->withToken($token)->postJson('/api/v1/accounts', [
            'name' => 'Efectivo Personal',
            'type' => 'cash',
            'currency' => 'DOP',
            'opening_balance' => '2000.00',
        ])->assertCreated();

        $this->assertDatabaseCount('accounts', 3);
        $this->assertEquals('22000.00', FinancialTransaction::where('user_id', $user->id)->sum('amount'));
    }

    public function test_user_can_update_and_delete_or_archive_account(): void
    {
        $user = User::factory()->create();
        $account = Account::create([
            'user_id' => $user->id,
            'name' => 'Cuenta Vieja',
            'type' => 'checking',
            'currency' => 'DOP',
            'is_active' => true,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        // Update account
        $updateResponse = $this->withToken($token)->putJson("/api/v1/accounts/{$account->id}", [
            'name' => 'Cuenta Principal Editada',
            'institution' => 'Banco Banreservas',
            'type' => 'savings',
        ]);
        $updateResponse->assertOk()
            ->assertJsonPath('data.name', 'Cuenta Principal Editada')
            ->assertJsonPath('data.institution', 'Banco Banreservas');

        // Delete account without transactions (hard delete)
        $deleteResponse = $this->withToken($token)->deleteJson("/api/v1/accounts/{$account->id}");
        $deleteResponse->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('accounts', ['id' => $account->id]);
    }

    public function test_user_can_record_direct_expense(): void
    {
        $user = User::factory()->create();
        $account = Account::create([
            'user_id' => $user->id,
            'name' => 'Cuenta Ahorros',
            'type' => 'savings',
            'currency' => 'DOP',
            'is_active' => true,
        ]);
        FinancialTransaction::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'description' => 'Balance inicial',
            'amount' => '5000.00',
            'occurred_at' => now(),
            'kind' => 'account_opening',
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Combustible',
            'amount' => '2000.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Gasolina para vehículo',
            'idempotency_key' => '123e4567-e89b-12d3-a456-426614174999',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('expenses', [
            'user_id' => $user->id,
            'amount' => '2000.00',
            'category' => 'Combustible',
        ]);

        $this->assertEquals('3000.00', (string) FinancialTransaction::where('account_id', $account->id)->sum('amount'));
    }

    public function test_credit_card_expense_updates_debt_balance(): void
    {
        $user = User::factory()->create();
        $account = Account::create([
            'user_id' => $user->id,
            'name' => 'Tarjeta principal',
            'type' => 'credit_card',
            'currency' => 'DOP',
            'is_active' => true,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Compras',
            'amount' => '1200.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Compra con tarjeta',
            'idempotency_key' => '123e4567-e89b-12d3-a456-426614174998',
        ])->assertCreated();

        $this->assertDatabaseHas('debts', [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'outstanding_amount' => '1200.00',
            'minimum_payment' => '0.00',
            'minimum_payment_confirmed' => false,
        ]);
        $this->assertDatabaseHas('debt_balance_movements', [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'kind' => 'increase',
            'delta' => '1200.00',
        ]);
    }

    public function test_internal_transfer_does_not_alter_total_user_wealth(): void
    {
        $user = User::factory()->create();
        $acc1 = Account::create(['user_id' => $user->id, 'name' => 'Ahorros', 'type' => 'savings', 'currency' => 'DOP', 'is_active' => true]);
        $acc2 = Account::create(['user_id' => $user->id, 'name' => 'Efectivo', 'type' => 'cash', 'currency' => 'DOP', 'is_active' => true]);

        // Opening balance: Acc1 = 20000, Acc2 = 5000 (Total = 25000)
        FinancialTransaction::create(['user_id' => $user->id, 'account_id' => $acc1->id, 'description' => 'Apertura', 'amount' => '20000.00', 'occurred_at' => now(), 'kind' => 'account_opening']);
        FinancialTransaction::create(['user_id' => $user->id, 'account_id' => $acc2->id, 'description' => 'Apertura', 'amount' => '5000.00', 'occurred_at' => now(), 'kind' => 'account_opening']);

        $token = $user->createToken('test-token')->plainTextToken;

        // Transfer 3000 from Acc1 to Acc2
        $response = $this->withToken($token)->postJson('/api/v1/transfers', [
            'source_account_id' => $acc1->id,
            'destination_account_id' => $acc2->id,
            'amount' => '3000.00',
            'transferred_at' => now()->toDateString(),
            'notes' => 'Retiro para efectivo',
            'idempotency_key' => '123e4567-e89b-12d3-a456-426614174888',
        ]);

        $response->assertCreated();

        // Acc1 balance = 17000, Acc2 balance = 8000
        $this->assertEquals('17000.00', FinancialTransaction::where('account_id', $acc1->id)->sum('amount'));
        $this->assertEquals('8000.00', FinancialTransaction::where('account_id', $acc2->id)->sum('amount'));

        // Total user wealth remains exactly 25000.00
        $totalWealth = FinancialTransaction::where('user_id', $user->id)->sum('amount');
        $this->assertEquals('25000.00', $totalWealth);
    }

    public function test_user_cannot_record_expense_with_insufficient_funds(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['user_id' => $user->id, 'name' => 'Ahorros', 'type' => 'savings', 'currency' => 'DOP', 'is_active' => true]);
        FinancialTransaction::create(['user_id' => $user->id, 'account_id' => $account->id, 'description' => 'Depósito', 'amount' => '500.00', 'occurred_at' => now(), 'kind' => 'account_opening']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Alimentación',
            'amount' => '1500.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Supermercado grande',
            'idempotency_key' => '123e4567-e89b-12d3-a456-426614174777',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_user_can_update_and_delete_income(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $createResp = $this->withToken($token)->postJson('/api/v1/incomes', [
            'source' => 'Trabajo freelance',
            'amount' => '10000.00',
            'expected_date' => now()->toDateString(),
        ])->assertCreated();

        $incomeId = $createResp->json('data.id');

        $this->withToken($token)->putJson("/api/v1/incomes/{$incomeId}", [
            'source' => 'Trabajo freelance actualizado',
            'amount' => '12000.00',
            'expected_date' => now()->toDateString(),
        ])->assertOk()->assertJsonPath('data.source', 'Trabajo freelance actualizado');

        $this->withToken($token)->deleteJson("/api/v1/incomes/{$incomeId}")->assertOk();
        $this->assertDatabaseMissing('incomes', ['id' => $incomeId]);
    }

    public function test_user_can_update_and_delete_expense(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['user_id' => $user->id, 'name' => 'Corriente', 'type' => 'checking', 'currency' => 'DOP', 'is_active' => true]);
        FinancialTransaction::create(['user_id' => $user->id, 'account_id' => $account->id, 'description' => 'Fondo inicial', 'amount' => '10000.00', 'occurred_at' => now(), 'kind' => 'account_opening']);
        $token = $user->createToken('test-token')->plainTextToken;

        $createResp = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Combustible',
            'amount' => '2000.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Gasolina',
            'idempotency_key' => '123e4567-e89b-12d3-a456-426614174666',
        ])->assertCreated();

        $expenseId = $createResp->json('data.id');

        $this->withToken($token)->putJson("/api/v1/expenses/{$expenseId}", [
            'account_id' => $account->id,
            'category' => 'Combustible',
            'amount' => '2500.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Gasolina Premium',
        ])->assertOk()->assertJsonPath('data.description', 'Gasolina Premium');

        $this->withToken($token)->deleteJson("/api/v1/expenses/{$expenseId}")->assertOk();
        $this->assertDatabaseMissing('expenses', ['id' => $expenseId]);
    }
}
