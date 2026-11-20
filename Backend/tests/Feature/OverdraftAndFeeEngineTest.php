<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use App\Models\CreditCardFeeRule;
use App\Models\Expense;
use App\Models\User;
use App\Services\CreditCardAuthorizationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverdraftAndFeeEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_scenario_a_purchase_within_approved_credit_limit(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Register card: Limit RD$50,000, Overdraft RD$10,000, Used RD$40,000
        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Visa Escenario A',
            'type' => 'credit_card',
            'reference_number' => '1111',
            'institution' => 'Banco Popular',
            'current_balance' => '40000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => '50000.00',
            'authorized_overdraft_limit' => '10000.00',
        ]);

        $account = Account::where('user_id', $user->id)->where('name', 'Visa Escenario A')->firstOrFail();
        $debt = Debt::where('user_id', $user->id)->where('name', 'Visa Escenario A')->firstOrFail();

        // Purchase of RD$5,000
        $response = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Alimentación',
            'amount' => '5000.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Compra Dentro del Límite',
            'idempotency_key' => '11111111-1111-1111-1111-111111111111',
        ]);

        $response->assertCreated();

        $debt->refresh();
        $this->assertEquals('45000.00', (string) $debt->outstanding_amount);

        // Verify metrics
        $engine = app(CreditCardAuthorizationEngine::class);
        $metrics = $engine->getCapacityMetrics($debt);

        $this->assertEquals('45000.00', $metrics['used_balance']);
        $this->assertEquals('5000.00', $metrics['remaining_approved_credit']);
        $this->assertEquals('0.00', $metrics['used_overdraft']);
        $this->assertEquals('10000.00', $metrics['remaining_overdraft']);
        $this->assertEquals('15000.00', $metrics['remaining_total_capacity']);

        // Assert NO overdraft fee was generated
        $this->assertDatabaseMissing('debt_balance_movements', [
            'debt_id' => $debt->id,
            'kind' => 'overdraft_fee',
        ]);
    }

    public function test_scenario_b_purchase_uses_overdraft_and_generates_fixed_fee(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Register card: Limit RD$50,000, Overdraft RD$10,000, Used RD$48,000, Fee Fixed RD$500
        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Visa Escenario B',
            'type' => 'credit_card',
            'reference_number' => '2222',
            'institution' => 'Banco BHD',
            'current_balance' => '48000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => '50000.00',
            'authorized_overdraft_limit' => '10000.00',
            'overdraft_fee_type' => 'fixed',
            'overdraft_fee_amount' => '500.00',
        ]);

        $account = Account::where('user_id', $user->id)->where('name', 'Visa Escenario B')->firstOrFail();
        $debt = Debt::where('user_id', $user->id)->where('name', 'Visa Escenario B')->firstOrFail();

        // Purchase of RD$5,000
        $response = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Combustible',
            'amount' => '5000.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Gasolina Sobregiro',
            'idempotency_key' => '22222222-2222-2222-2222-222222222222',
        ]);

        $response->assertCreated();

        // Outstanding amount: 48000 + 5000 (purchase) + 500 (fee) = 53500.00
        $debt->refresh();
        $this->assertEquals('53500.00', (string) $debt->outstanding_amount);

        // Verify overdraft fee movement exists
        $this->assertDatabaseHas('debt_balance_movements', [
            'debt_id' => $debt->id,
            'kind' => 'overdraft_fee',
            'delta' => '500.00',
        ]);

        // Metrics: Used balance 53,500. Used overdraft = 3,500. Remaining overdraft = 6,500
        $engine = app(CreditCardAuthorizationEngine::class);
        $metrics = $engine->getCapacityMetrics($debt);

        $this->assertEquals('53500.00', $metrics['used_balance']);
        $this->assertEquals('0.00', $metrics['remaining_approved_credit']);
        $this->assertEquals('3500.00', $metrics['used_overdraft']);
        $this->assertEquals('6500.00', $metrics['remaining_overdraft']);
        $this->assertEquals('6500.00', $metrics['remaining_total_capacity']);
    }

    public function test_scenario_b2_purchase_uses_overdraft_with_percentage_fee_and_tax(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Register card: Limit RD$50,000, Overdraft RD$10,000, Used RD$48,000, Fee Pct 2%, Tax 15%
        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Visa Pct Fee',
            'type' => 'credit_card',
            'reference_number' => '3333',
            'institution' => 'Banco Santa Cruz',
            'current_balance' => '48000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => '50000.00',
            'authorized_overdraft_limit' => '10000.00',
            'overdraft_fee_type' => 'percentage',
            'overdraft_fee_percentage' => '2.00',
            'overdraft_fee_tax_rate' => '15.00',
        ]);

        $account = Account::where('user_id', $user->id)->where('name', 'Visa Pct Fee')->firstOrFail();
        $debt = Debt::where('user_id', $user->id)->where('name', 'Visa Pct Fee')->firstOrFail();

        // Purchase: RD$5,000 -> prospective 53,000 -> overdraft portion 3,000 -> 2% fee = 60.00 -> 15% tax = 9.00 -> total fee = 69.00
        $response = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Equipos',
            'amount' => '5000.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Compra Laptop',
            'idempotency_key' => '33333333-3333-3333-3333-333333333333',
        ]);

        $response->assertCreated();

        // Outstanding amount: 48,000 + 5,000 + 69 = 53,069.00
        $debt->refresh();
        $this->assertEquals('53069.00', (string) $debt->outstanding_amount);

        $this->assertDatabaseHas('debt_balance_movements', [
            'debt_id' => $debt->id,
            'kind' => 'overdraft_fee',
            'delta' => '69.00',
        ]);
    }

    public function test_scenario_c_purchase_exceeding_total_authorized_capacity_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Register card: Limit RD$50,000, Overdraft RD$10,000 (Total Capacity: RD$60,000), Used RD$58,000
        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Visa Escenario C',
            'type' => 'credit_card',
            'reference_number' => '4444',
            'institution' => 'Banco Popular',
            'current_balance' => '58000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => '50000.00',
            'authorized_overdraft_limit' => '10000.00',
        ]);

        $account = Account::where('user_id', $user->id)->where('name', 'Visa Escenario C')->firstOrFail();
        $debt = Debt::where('user_id', $user->id)->where('name', 'Visa Escenario C')->firstOrFail();

        // Purchase of RD$5,000 -> would require RD$63,000 > RD$60,000 total capacity
        $response = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Viajes',
            'amount' => '5000.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Boleto Aéreo',
            'idempotency_key' => '44444444-4444-4444-4444-444444444444',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('excede su capacidad total autorizada', $response->json('error.details.account_id.0'));

        // Assert no changes were made to card debt balance
        $debt->refresh();
        $this->assertEquals('58000.00', (string) $debt->outstanding_amount);
    }

    public function test_unknown_limit_status_rejects_purchase(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Visa Límite Desconocido',
            'type' => 'credit_card',
            'reference_number' => '9999',
            'institution' => 'Banco X',
            'current_balance' => '100.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => '0.00',
        ])->assertCreated();

        $account = Account::where('user_id', $user->id)->where('name', 'Visa Límite Desconocido')->firstOrFail();
        $debt = Debt::where('user_id', $user->id)->where('name', 'Visa Límite Desconocido')->firstOrFail();
        $debt->update(['limit_status' => 'unknown']);

        $response = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Varios',
            'amount' => '1000.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Compra desconocida',
            'idempotency_key' => '99999999-9999-9999-9999-999999999999',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('estado de límite desconocido', $response->json('error.details.account_id.0'));
    }

    public function test_confirmed_zero_limits_do_not_grant_unlimited_credit(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Tarjeta Cero',
            'type' => 'credit_card',
            'reference_number' => '0001',
            'institution' => 'Banco X',
            'current_balance' => '0.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => '0.00',
            'authorized_overdraft_limit' => '0.00',
            'limit_status' => 'confirmed',
        ])->assertCreated();

        $account = Account::where('user_id', $user->id)->where('name', 'Tarjeta Cero')->firstOrFail();

        $response = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Varios',
            'amount' => '1.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Compra sin capacidad',
            'idempotency_key' => '00000000-0000-0000-0000-000000000001',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('límite aprobado y sobregiro en cero', $response->json('error.details.account_id.0'));
    }

    public function test_pending_verification_rejects_purchase(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Tarjeta Pendiente',
            'type' => 'credit_card',
            'reference_number' => '0002',
            'institution' => 'Banco X',
            'current_balance' => '0.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => '50000.00',
            'authorized_overdraft_limit' => '10000.00',
            'limit_status' => 'pending_verification',
        ])->assertCreated();

        $account = Account::where('user_id', $user->id)->where('name', 'Tarjeta Pendiente')->firstOrFail();
        $response = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Varios',
            'amount' => '100.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Compra pendiente',
            'idempotency_key' => '00000000-0000-0000-0000-000000000002',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('pendiente de verificación', $response->json('error.details.account_id.0'));
    }

    public function test_special_rules_require_documentation(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Tarjeta Especial',
            'type' => 'credit_card',
            'reference_number' => '0003',
            'institution' => 'Banco X',
            'current_balance' => '0.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => '1000.00',
            'authorized_overdraft_limit' => '0.00',
            'limit_status' => 'special_rules',
        ])->assertStatus(422);
    }

    public function test_documented_special_rules_still_respect_total_capacity(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $create = $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Tarjeta Especial Documentada',
            'type' => 'credit_card',
            'reference_number' => '0004',
            'institution' => 'Banco X',
            'current_balance' => '900.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => '1000.00',
            'authorized_overdraft_limit' => '0.00',
            'limit_status' => 'special_rules',
            'special_rules_documented' => true,
            'special_rules_source' => 'https://example.com/contract',
            'special_rules_reference' => 'Contrato-RD-0004',
        ]);

        $create->assertCreated();

        $account = Account::where('user_id', $user->id)->where('name', 'Tarjeta Especial Documentada')->firstOrFail();
        $response = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Varios',
            'amount' => '200.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Compra sobre capacidad especial',
            'idempotency_key' => '00000000-0000-0000-0000-000000000004',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('excede su capacidad total autorizada', $response->json('error.details.account_id.0'));
    }

    public function test_card_owner_can_confirm_limits_then_retry_purchase(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Tarjeta Por Configurar',
            'type' => 'credit_card',
            'reference_number' => '0005',
            'institution' => 'Banco X',
            'current_balance' => '0.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
        ])->assertCreated();

        $account = Account::where('user_id', $user->id)->where('name', 'Tarjeta Por Configurar')->firstOrFail();
        $debt = Debt::where('user_id', $user->id)->where('name', 'Tarjeta Por Configurar')->firstOrFail();

        $rejected = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Varios',
            'amount' => '100.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Compra antes de confirmar',
            'idempotency_key' => '00000000-0000-0000-0000-000000000005',
        ]);
        $rejected->assertStatus(422);

        $this->withToken($token)->patchJson("/api/v1/debts/{$debt->id}/card-limits", [
            'approved_credit_limit' => '50000.00',
            'authorized_overdraft_limit' => '10000.00',
            'limit_status' => 'confirmed',
        ])->assertOk();

        $retried = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Varios',
            'amount' => '100.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Compra después de confirmar',
            'idempotency_key' => '00000000-0000-0000-0000-000000000006',
        ]);
        $retried->assertCreated();

        $debt->refresh();
        $this->assertEquals('100.00', (string) $debt->outstanding_amount);
        $this->assertEquals('confirmed', $debt->limit_status);
    }

    public function test_entry_fee_is_charged_once_and_reversed_with_its_expense(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Tarjeta Evento Único',
            'type' => 'credit_card',
            'reference_number' => '0006',
            'institution' => 'Banco X',
            'current_balance' => '48000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => '50000.00',
            'authorized_overdraft_limit' => '10000.00',
            'limit_status' => 'confirmed',
            'overdraft_fee_type' => 'fixed',
            'overdraft_fee_amount' => '500.00',
        ])->assertCreated();

        $account = Account::where('user_id', $user->id)->where('name', 'Tarjeta Evento Único')->firstOrFail();
        $debt = Debt::where('user_id', $user->id)->where('name', 'Tarjeta Evento Único')->firstOrFail();

        $firstKey = '00000000-0000-0000-0000-000000000007';
        $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Varios',
            'amount' => '3000.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Entrada en sobregiro',
            'idempotency_key' => $firstKey,
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Varios',
            'amount' => '2000.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Compra consecutiva',
            'idempotency_key' => '00000000-0000-0000-0000-000000000008',
        ])->assertCreated();

        $feeCount = DebtBalanceMovement::query()
            ->where('debt_id', $debt->id)
            ->where('kind', 'overdraft_fee')
            ->count();
        $this->assertEquals(1, $feeCount);

        $expense = Expense::where('user_id', $user->id)
            ->where('idempotency_key', $firstKey)
            ->firstOrFail();

        $this->withToken($token)->deleteJson("/api/v1/expenses/{$expense->id}")
            ->assertOk();

        $debt->refresh();
        $this->assertEquals('50000.00', (string) $debt->outstanding_amount);
        $this->assertDatabaseHas('debt_balance_movements', [
            'debt_id' => $debt->id,
            'kind' => 'reversal',
            'delta' => '-500.00',
            'event_idempotency_key' => $firstKey,
        ]);
    }

    public function test_verified_period_rule_is_not_repeated_in_the_same_month(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Tarjeta Regla Periódica',
            'type' => 'credit_card',
            'reference_number' => '0009',
            'institution' => 'Banco X',
            'current_balance' => '48000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => '50000.00',
            'authorized_overdraft_limit' => '10000.00',
            'limit_status' => 'confirmed',
        ])->assertCreated();

        $debt = Debt::where('user_id', $user->id)->where('name', 'Tarjeta Regla Periódica')->firstOrFail();
        CreditCardFeeRule::query()->create([
            'trigger' => 'period',
            'formula_type' => 'fixed',
            'formula_value' => '250.00',
            'frequency' => 'monthly',
            'maximum_amount' => '250.00',
            'tax_rate' => '0.00',
            'effective_from' => now()->toDateString(),
            'source_url' => 'https://example.com/contract',
            'verification_status' => 'verified',
            'is_active' => true,
        ]);

        $account = Account::where('user_id', $user->id)->where('name', 'Tarjeta Regla Periódica')->firstOrFail();
        $engine = app(CreditCardAuthorizationEngine::class);

        $first = $engine->authorizeTransaction($debt, '3000.00', '00000000-0000-0000-0000-000000000010');
        $this->assertEquals('250.00', $first['total_fee']);

        DebtBalanceMovement::query()->create([
            'user_id' => $user->id,
            'debt_id' => $debt->id,
            'account_id' => $account->id,
            'kind' => 'overdraft_fee',
            'delta' => '250.00',
            'description' => '[fee-rule:' . $first['fee_rule']['id'] . ']',
            'occurred_at' => now(),
            'idempotency_key' => $engine->feeEventIdempotencyKey('00000000-0000-0000-0000-000000000010', $first['fee_rule']['id']),
            'event_idempotency_key' => '00000000-0000-0000-0000-000000000010',
        ]);

        $second = $engine->authorizeTransaction($debt, '1000.00', '00000000-0000-0000-0000-000000000011');
        $this->assertEquals('0.00', $second['total_fee']);
    }
}

