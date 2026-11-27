<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\CreditCardStatement;
use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreditCardStatementTest extends TestCase
{
    use RefreshDatabase;

    public function test_missed_cutoffs_are_backfilled_once_from_debt_movements_and_keep_confirmed_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00'));

        try {
            [$user, $commitment, $debt] = $this->createCardHistory();
            $token = $user->createToken('test-token')->plainTextToken;

            $this->artisan('finance:generate-occurrences')->assertSuccessful();
            $this->assertDatabaseCount('commitment_occurrences', 0);

            $this->artisan('finance:process-card-cutoffs')->assertSuccessful();

            $this->assertSame(
                [
                    ['statement_date' => '2026-01-15', 'closing_balance' => '1000.00'],
                    ['statement_date' => '2026-02-15', 'closing_balance' => '1050.00'],
                    ['statement_date' => '2026-03-15', 'closing_balance' => '1075.00'],
                ],
                CreditCardStatement::query()
                    ->where('commitment_id', $commitment->id)
                    ->orderBy('statement_date')
                    ->get(['statement_date', 'closing_balance'])
                    ->map(fn (CreditCardStatement $statement) => [
                        'statement_date' => $statement->statement_date->toDateString(),
                        'closing_balance' => $statement->closing_balance,
                    ])
                    ->all(),
            );
            $this->assertSame('1075.00', $debt->fresh()->outstanding_amount);

            $this->withToken($token)->patchJson("/api/v1/debts/{$debt->id}/minimum-payment", [
                'minimum_payment' => '85.00',
            ])->assertOk();

            $this->artisan('finance:process-card-cutoffs')->assertSuccessful();
            $this->assertDatabaseCount('credit_card_statements', 3);
            $this->assertDatabaseHas('credit_card_statements', [
                'commitment_id' => $commitment->id,
                'statement_date' => '2026-03-15',
                'minimum_payment' => '85.00',
                'minimum_payment_confirmed' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-04-16 12:00:00'));
            $this->artisan('finance:process-card-cutoffs')->assertSuccessful();

            $this->assertDatabaseCount('credit_card_statements', 4);
            $this->assertDatabaseHas('credit_card_statements', [
                'commitment_id' => $commitment->id,
                'statement_date' => '2026-04-15',
                'closing_balance' => '1075.00',
                'minimum_payment_confirmed' => false,
            ]);
            $this->assertDatabaseHas('credit_card_statements', [
                'commitment_id' => $commitment->id,
                'statement_date' => '2026-03-15',
                'minimum_payment' => '85.00',
                'minimum_payment_confirmed' => true,
            ]);
            $this->assertFalse($debt->fresh()->minimum_payment_confirmed);

            $this->withToken($token)
                ->getJson("/api/v1/debts/{$debt->id}/statements")
                ->assertOk()
                ->assertJsonCount(4, 'data')
                ->assertJsonPath('data.1.minimum_payment', '85.00')
                ->assertJsonPath('data.1.minimum_payment_confirmed', true);

            $otherUser = User::factory()->create();
            $this->actingAs($otherUser, 'sanctum')
                ->getJson("/api/v1/debts/{$debt->id}/statements")
                ->assertNotFound();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_minimum_payment_mode_uses_the_minimum_reported_for_the_new_statement_due_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00'));

        try {
            [$user, $commitment, $debt] = $this->createCardHistory();
            $account = Account::query()->where('user_id', $user->id)->where('name', $commitment->name)->firstOrFail();
            $commitment->update([
                'account_id' => $account->id,
                'debt_id' => $debt->id,
                'card_payment_mode' => 'minimum',
            ]);
            $debt->update(['commitment_id' => $commitment->id]);

            $this->artisan('finance:process-card-cutoffs')->assertSuccessful();

            $statement = CreditCardStatement::query()
                ->where('commitment_id', $commitment->id)
                ->orderByDesc('statement_date')
                ->firstOrFail();
            $this->assertFalse(CommitmentOccurrence::query()
                ->where('commitment_id', $commitment->id)
                ->whereDate('due_date', $statement->due_date->toDateString())
                ->exists());
            $unconfirmedPlan = app(\App\Services\FinancialPlanningService::class)->calculate($user->id, 60);
            $this->assertSame('0.00', $unconfirmedPlan['summary']['pending_obligations']);
            $this->assertContains('Pago mínimo no confirmado para ' . $commitment->name, $unconfirmedPlan['data_completeness']['missing_information']);

            $token = $user->createToken('test-token')->plainTextToken;
            $this->withToken($token)->putJson("/api/v1/commitments/{$commitment->id}", [
                'name' => $commitment->name,
                'type' => 'credit_card',
                'reference_number' => '1234',
                'institution' => 'Banco Popular',
                'current_balance' => '1075.00',
                'statement_balance' => '1075.00',
                'minimum_payment' => '85.00',
                'card_payment_mode' => 'minimum',
                'statement_day' => 15,
                'payment_grace_days' => 21,
                'interest_rate' => '0.00',
            ])->assertOk();

            $occurrence = CommitmentOccurrence::query()
                ->where('commitment_id', $commitment->id)
                ->whereDate('due_date', $statement->due_date->toDateString())
                ->firstOrFail();
            $this->assertSame('85.00', (string) $occurrence->amount_due);
            $this->assertSame($statement->due_date->toDateString(), $occurrence->due_date->toDateString());

            $plan = app(\App\Services\FinancialPlanningService::class)->calculate($user->id, 60);
            $this->assertSame('85.00', $plan['summary']['pending_obligations']);
            $this->assertNotContains('Pago mínimo no confirmado para ' . $commitment->name, $plan['data_completeness']['missing_information']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_financing_interest_is_daily_on_unpaid_statement_principal_and_posted_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-16 12:00:00'));

        try {
            [$user, $commitment, $debt] = $this->createCardHistory();
            $commitment->update(['interest_rate' => '36.500']);
            $this->createMovement($user->id, $debt->id, 'payment', '-500.00', '2026-02-10 12:00:00');

            $this->artisan('finance:process-card-cutoffs')->assertSuccessful();

            $interest = DebtBalanceMovement::query()
                ->where('debt_id', $debt->id)
                ->where('kind', 'financing_interest')
                ->firstOrFail();
            $this->assertSame('6.70', $interest->delta);
            $this->assertSame('556.70', $debt->fresh()->outstanding_amount);
            $this->assertDatabaseHas('credit_card_statements', [
                'commitment_id' => $commitment->id,
                'statement_date' => '2026-02-15',
                'closing_balance' => '556.70',
            ]);

            $this->artisan('finance:process-card-cutoffs')->assertSuccessful();
            $this->assertDatabaseCount('debt_balance_movements', 6);
            $this->assertSame('556.70', $debt->fresh()->outstanding_amount);

            Carbon::setTestNow(Carbon::parse('2026-03-16 12:00:00'));
            $this->artisan('finance:process-card-cutoffs')->assertSuccessful();
            $this->assertDatabaseHas('debt_balance_movements', [
                'debt_id' => $debt->id,
                'kind' => 'financing_interest',
                'delta' => '3.85',
                'occurred_at' => '2026-03-15 23:59:59',
            ]);
            $this->assertDatabaseHas('credit_card_statements', [
                'commitment_id' => $commitment->id,
                'statement_date' => '2026-03-15',
                'closing_balance' => '585.55',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_commitment_payment_and_reversal_both_change_card_statement_balance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-14 12:00:00'));

        try {
            $user = User::factory()->create();
            $cardAccount = Account::query()->create([
                'user_id' => $user->id,
                'name' => 'Visa',
                'type' => 'credit_card',
                'currency' => 'DOP',
                'is_active' => true,
            ]);
            $cashAccount = Account::query()->create([
                'user_id' => $user->id,
                'name' => 'Checking',
                'type' => 'checking',
                'currency' => 'DOP',
                'is_active' => true,
            ]);
            $commitment = Commitment::query()->create([
                'user_id' => $user->id,
                'name' => 'Visa',
                'type' => 'credit_card',
                'amount' => '1000.00',
                'frequency' => 'monthly',
                'next_due_date' => '2026-01-15',
                'is_active' => true,
                'opening_balance' => '1000.00',
                'current_balance' => '1000.00',
                'interest_rate' => '0.000',
                'statement_day' => 15,
                'payment_grace_days' => 21,
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]);
            $occurrence = CommitmentOccurrence::query()->create([
                'user_id' => $user->id,
                'commitment_id' => $commitment->id,
                'amount_due' => '1000.00',
                'due_date' => '2026-01-15',
                'status' => 'scheduled',
            ]);
            $debt = Debt::query()->create([
                'user_id' => $user->id,
                'account_id' => $cardAccount->id,
                'name' => 'Visa',
                'kind' => 'credit_card',
                'outstanding_amount' => '1000.00',
                'minimum_payment' => '0.00',
                'minimum_payment_confirmed' => false,
            ]);
            $this->createMovement($user->id, $debt->id, 'opening_balance', '1000.00', '2026-01-01 00:00:00');
            $token = $user->createToken('test-token')->plainTextToken;

            $paymentResponse = $this->withToken($token)
                ->postJson("/api/v1/commitments/{$commitment->id}/occurrences/{$occurrence->id}/payments", [
                    'account_id' => $cashAccount->id,
                    'amount' => '100.00',
                    'paid_at' => '2026-01-15 10:00:00',
                    'idempotency_key' => '123e4567-e89b-12d3-a456-426614174008',
                ])->assertCreated();
            $paymentId = $paymentResponse->json('data.id');

            $this->assertSame('900.00', $debt->fresh()->outstanding_amount);
            $this->assertSame(
                '900.00',
                bcadd((string) DebtBalanceMovement::query()->where('debt_id', $debt->id)->sum('delta'), '0.00', 2),
            );

            Carbon::setTestNow(Carbon::parse('2026-01-16 12:00:00'));
            $otherUser = User::factory()->create();
            $this->actingAs($otherUser, 'sanctum')
                ->postJson("/api/v1/payments/{$paymentId}/reversals", [
                    'reason' => 'No corresponde al usuario',
                    'idempotency_key' => '123e4567-e89b-12d3-a456-426614174011',
                ])
                ->assertNotFound();
            $this->actingAs($user, 'sanctum')->postJson("/api/v1/payments/{$paymentId}/reversals", [
                'reason' => 'Prueba de reversión',
                'idempotency_key' => '123e4567-e89b-12d3-a456-426614174009',
            ])->assertCreated();

            $this->assertSame('1000.00', $debt->fresh()->outstanding_amount);

            Carbon::setTestNow(Carbon::parse('2026-02-16 12:00:00'));
            $this->artisan('finance:process-card-cutoffs')->assertSuccessful();
            $this->assertDatabaseHas('credit_card_statements', [
                'commitment_id' => $commitment->id,
                'statement_date' => '2026-01-15',
                'closing_balance' => '900.00',
            ]);
            $this->assertDatabaseHas('credit_card_statements', [
                'commitment_id' => $commitment->id,
                'statement_date' => '2026-02-15',
                'closing_balance' => '1000.00',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function createCardHistory(): array
    {
        $user = User::factory()->create();
        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'Visa histórica',
            'type' => 'credit_card',
            'currency' => 'DOP',
            'is_active' => true,
        ]);
        $commitment = Commitment::query()->create([
            'user_id' => $user->id,
            'name' => 'Visa histórica',
            'type' => 'credit_card',
            'amount' => '1000.00',
            'frequency' => 'monthly',
            'next_due_date' => '2026-02-05',
            'is_active' => true,
            'opening_balance' => '1000.00',
            'current_balance' => '1075.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $debt = Debt::query()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'name' => 'Visa histórica',
            'kind' => 'credit_card',
            'outstanding_amount' => '1075.00',
            'minimum_payment' => '50.00',
            'minimum_payment_confirmed' => true,
        ]);

        $this->createMovement($user->id, $debt->id, 'opening_balance', '1000.00', '2026-01-01 00:00:00');
        $this->createMovement($user->id, $debt->id, 'increase', '100.00', '2026-01-20 12:00:00');
        $this->createMovement($user->id, $debt->id, 'payment', '-50.00', '2026-02-10 12:00:00');
        $this->createMovement($user->id, $debt->id, 'increase', '25.00', '2026-03-01 12:00:00');

        return [$user, $commitment, $debt];
    }

    private function createMovement(int $userId, int $debtId, string $kind, string $delta, string $occurredAt): void
    {
        DebtBalanceMovement::query()->create([
            'user_id' => $userId,
            'debt_id' => $debtId,
            'kind' => $kind,
            'delta' => $delta,
            'description' => $kind,
            'occurred_at' => $occurredAt,
            'idempotency_key' => Str::uuid()->toString(),
        ]);
    }
}
