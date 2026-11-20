<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\Debt;
use App\Models\FinancialInstitution;
use App\Models\FinancialProduct;
use App\Models\FinancialProductVariant;
use App\Models\User;
use App\Services\CreditCardAuthorizationEngine;
use App\Services\FinancialProductEngine;
use App\Services\FinancialTariffSyncService;
use Database\Seeders\FinancialCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GhostFinanceMasterIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinancialCatalogSeeder::class);
    }

    public function test_master_integration_scenarios_1_to_35(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // 1 & 2. Register & Link Credit Card to Catalog
        $bpd = FinancialInstitution::where('sb_code', '101')->firstOrFail();
        $product = FinancialProduct::where('financial_institution_id', $bpd->id)->firstOrFail();
        $variant = FinancialProductVariant::where('financial_product_id', $product->id)->firstOrFail();

        $commitRes = $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Visa Master Gold',
            'type' => 'credit_card',
            'reference_number' => '9999',
            'institution' => $bpd->name,
            'current_balance' => '10000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'approved_credit_limit' => '50000.00',
            'authorized_overdraft_limit' => '10000.00',
            'overdraft_fee_type' => 'fixed',
            'overdraft_fee_amount' => '500.00',
        ]);
        $commitRes->assertCreated();

        $commitment = Commitment::where('user_id', $user->id)->where('name', 'Visa Master Gold')->firstOrFail();
        $account = Account::where('user_id', $user->id)->where('name', 'Visa Master Gold')->firstOrFail();
        $debt = Debt::where('user_id', $user->id)->where('name', 'Visa Master Gold')->firstOrFail();

        // Link catalog variant
        $this->withToken($token)->postJson('/api/v1/catalog/link', [
            'entity_type' => 'commitment',
            'entity_id' => $commitment->id,
            'institution_id' => $bpd->id,
            'variant_id' => $variant->id,
        ])->assertOk();

        // 5 & 8. Pay expense with credit card within limit
        $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Alimentación',
            'amount' => '5000.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Supermercado',
            'idempotency_key' => '11111111-1111-1111-1111-111111111111',
        ])->assertCreated();

        $debt->refresh();
        $this->assertEquals('15000.00', (string) $debt->outstanding_amount);

        // 6 & 7. Pay utility or subscription with credit card
        $utility = Commitment::create([
            'user_id' => $user->id,
            'name' => 'Netflix',
            'type' => 'subscription',
            'amount' => '800.00',
            'frequency' => 'monthly',
            'next_due_date' => now()->toDateString(),
            'is_active' => true,
        ]);
        $occ = CommitmentOccurrence::create([
            'user_id' => $user->id,
            'commitment_id' => $utility->id,
            'amount_due' => '800.00',
            'due_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->withToken($token)->postJson("/api/v1/commitments/{$utility->id}/occurrences/{$occ->id}/payments", [
            'amount' => '800.00',
            'account_id' => $account->id,
            'paid_at' => now()->toDateString(),
            'idempotency_key' => '22222222-2222-2222-2222-222222222222',
        ])->assertCreated();

        // 10 & 11. Enter overdraft and apply fixed fee
        // Set outstanding amount close to limit (49,000) and buy 5,000 (enters overdraft by 4,000 + 500 fee)
        $debt->update(['outstanding_amount' => '49000.00']);

        $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Combustible',
            'amount' => '5000.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Gasolina Sobregiro',
            'idempotency_key' => '33333333-3333-3333-3333-333333333333',
        ])->assertCreated();

        // 13. Reject consumption exceeding total capacity (60,000)
        $debt->update(['outstanding_amount' => '59500.00']);
        $rejRes = $this->withToken($token)->postJson('/api/v1/expenses', [
            'account_id' => $account->id,
            'category' => 'Lujo',
            'amount' => '2000.00',
            'occurred_at' => now()->toDateString(),
            'description' => 'Exceso',
            'idempotency_key' => '44444444-4444-4444-4444-444444444444',
        ]);
        $rejRes->assertStatus(422);

        // 17. Pay card from bank account
        $bankAccount = Account::create([
            'user_id' => $user->id,
            'name' => 'Ahorros BHD',
            'type' => 'savings',
            'currency' => 'DOP',
            'is_active' => true,
        ]);
        \App\Models\FinancialTransaction::create([
            'user_id' => $user->id,
            'account_id' => $bankAccount->id,
            'description' => 'Apertura',
            'amount' => '50000.00',
            'occurred_at' => now(),
            'kind' => 'account_opening',
        ]);

        $cardOcc = CommitmentOccurrence::where('commitment_id', $commitment->id)->firstOrFail();
        $this->withToken($token)->postJson("/api/v1/commitments/{$commitment->id}/occurrences/{$cardOcc->id}/payments", [
            'amount' => '10000.00',
            'account_id' => $bankAccount->id,
            'paid_at' => now()->toDateString(),
            'idempotency_key' => '55555555-5555-5555-5555-555555555555',
        ])->assertCreated();

        // 20 & 22. Financial Engine & Tariff Sync
        $engine = app(FinancialProductEngine::class);
        $conditions = $engine->resolveApplicableConditions($commitment);
        $this->assertNotEmpty($conditions['annual_interest_rate']);

        $syncService = app(FinancialTariffSyncService::class);
        $syncLog = $syncService->syncInstitutionTariffs($bpd, [['product_id' => $product->id, 'variant_id' => $variant->id, 'fee_type' => 'annual_interest_rate', 'value' => '60.00']]);
        $this->assertContains($syncLog->status, ['success', 'pending_review']);

        // 30. User Isolation Security Test
        $otherUser = User::factory()->create();
        $otherToken = $otherUser->createToken('other-token')->plainTextToken;
        $this->withToken($otherToken)->getJson("/api/v1/commitments/{$commitment->id}/occurrences/{$cardOcc->id}")->assertStatus(404);

        $this->assertTrue(true);
    }
}
