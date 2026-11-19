<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ImportedTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetReserveAndImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_manage_budgets_and_reserves(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('token')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/budgets', [
            'category' => 'Alimentación',
            'monthly_limit' => '500.00',
            'year_month' => '2026-11',
        ])->assertCreated();

        $this->withToken($token)
            ->getJson('/api/v1/budgets?year_month=2026-11')
            ->assertOk()
            ->assertJsonPath('data.0.category', 'Alimentación')
            ->assertJsonPath('data.0.monthly_limit', '500.00');

        $this->withToken($token)->postJson('/api/v1/reserves', [
            'name' => 'Fondo de Emergencia',
            'target_amount' => '2000.00',
            'current_amount' => '500.00',
        ])->assertCreated();

        $this->withToken($token)
            ->getJson('/api/v1/reserves')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Fondo de Emergencia')
            ->assertJsonPath('data.0.target_amount', '2000.00');
    }

    public function test_user_can_import_csv_statements_reconcile_and_revert(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('token')->plainTextToken;
        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'DOP',
        ]);

        $csv = "Date,Description,Amount\n2026-11-01,Supermercado,-150.00\n2026-11-02,Salario,2500.00";

        $this->withToken($token)->postJson('/api/v1/imports', [
            'account_id' => $account->id,
            'csv_content' => $csv,
        ])->assertCreated();

        $imported = ImportedTransaction::query()->where('description', 'Supermercado')->first();

        // Confirm reconciliation
        $this->withToken($token)->patchJson("/api/v1/imports/{$imported->id}", [
            'status' => 'confirmed',
        ])->assertOk()
          ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('transactions', [
            'account_id' => $account->id,
            'amount' => '-150.00',
            'kind' => 'expense',
        ]);

        // Revert reconciliation
        $this->withToken($token)->patchJson("/api/v1/imports/{$imported->id}", [
            'status' => 'pending',
        ])->assertOk()
          ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseMissing('transactions', [
            'account_id' => $account->id,
            'amount' => '-150.00',
            'kind' => 'expense',
        ]);
    }
}
