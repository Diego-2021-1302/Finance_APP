<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use App\Models\CreditCardStatement;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class InterestCutTest extends TestCase
{
    use RefreshDatabase;

    public function test_biweekly_interest_cut_details_daily_card_loan_and_overdraft_amounts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00'));

        try {
            $user = User::factory()->create();
            UserPreference::query()->create([
                'user_id' => $user->id,
                'currency' => 'DOP',
                'timezone' => 'America/Santo_Domingo',
                'reminders' => 3,
                'week_starts' => 'Monday',
                'appearance' => 'system',
                'pay_frequency' => 'biweekly',
            ]);
            $token = $user->createToken('test-token')->plainTextToken;

            $cardAccount = Account::query()->create([
                'user_id' => $user->id,
                'name' => 'Visa diaria',
                'type' => 'credit_card',
                'currency' => 'DOP',
                'is_active' => true,
            ]);
            $cardCommitment = Commitment::query()->create([
                'user_id' => $user->id,
                'account_id' => $cardAccount->id,
                'name' => 'Visa diaria',
                'type' => 'credit_card',
                'amount' => '1000.00',
                'frequency' => 'monthly',
                'next_due_date' => '2026-12-10',
                'is_active' => true,
                'current_balance' => '500.00',
                'interest_rate' => '36.500',
                'approved_credit_limit' => '700.00',
                'overdraft_fee_type' => 'cutoff_daily',
                'overdraft_fee_percentage' => '36.50',
                'statement_day' => 15,
                'payment_grace_days' => 25,
            ]);
            $cardDebt = Debt::query()->create([
                'user_id' => $user->id,
                'account_id' => $cardAccount->id,
                'commitment_id' => $cardCommitment->id,
                'name' => 'Visa diaria',
                'kind' => 'credit_card',
                'outstanding_amount' => '500.00',
                'minimum_payment' => '50.00',
                'minimum_payment_confirmed' => true,
                'approved_credit_limit' => '700.00',
                'authorized_overdraft_limit' => '300.00',
            ]);
            $cardAccount->update(['debt_id' => $cardDebt->id]);
            $cardCommitment->update(['debt_id' => $cardDebt->id]);
            CreditCardStatement::query()->create([
                'user_id' => $user->id,
                'commitment_id' => $cardCommitment->id,
                'debt_id' => $cardDebt->id,
                'statement_date' => '2026-10-15',
                'closing_balance' => '1000.00',
                'due_date' => '2026-11-15',
            ]);

            Commitment::query()->create([
                'user_id' => $user->id,
                'name' => 'Préstamo personal',
                'type' => 'loan',
                'amount' => '500.00',
                'frequency' => 'monthly',
                'next_due_date' => '2026-11-30',
                'is_active' => true,
                'current_balance' => '2000.00',
                'interest_rate' => '24.333',
            ]);

            $this->movement($user->id, $cardDebt->id, 'opening_balance', '1000.00', '2026-11-16 00:00:00');
            $this->movement($user->id, $cardDebt->id, 'payment', '-500.00', '2026-11-18 10:00:00');

            $response = $this->withToken($token)->getJson('/api/v1/interests/cut');

            $response->assertOk()
                ->assertJsonPath('data.period.pay_frequency', 'biweekly')
                ->assertJsonPath('data.period.starts_on', '2026-11-16')
                ->assertJsonPath('data.period.ends_on', '2026-11-30')
                ->assertJsonPath('data.period.calculated_through', '2026-11-20')
                ->assertJsonPath('data.summary.card_financing_interest', '3.50')
                ->assertJsonPath('data.summary.loan_interest', '6.67')
                ->assertJsonPath('data.summary.financing_interest', '10.17')
                ->assertJsonPath('data.summary.overdraft_cut_charge', '0.60')
                ->assertJsonPath('data.summary.total', '10.77')
                ->assertJsonPath('data.items.0.name', 'Visa diaria')
                ->assertJsonPath('data.items.0.financing_interest', '3.50')
                ->assertJsonPath('data.items.0.overdraft_cut_charge', '0.60')
                ->assertJsonPath('data.items.0.overdraft_charge_status', 'estimated')
                ->assertJsonPath('data.items.1.name', 'Préstamo personal')
                ->assertJsonPath('data.items.1.financing_interest', '6.67');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_monthly_card_interest_matches_daily_balance_formula_for_constant_balance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-30 12:00:00'));

        try {
            $user = User::factory()->create();
            UserPreference::query()->create([
                'user_id' => $user->id,
                'currency' => 'DOP',
                'timezone' => 'America/Santo_Domingo',
                'reminders' => 3,
                'week_starts' => 'Monday',
                'appearance' => 'system',
                'pay_frequency' => 'monthly',
            ]);
            $token = $user->createToken('test-token')->plainTextToken;
            $commitment = Commitment::query()->create([
                'user_id' => $user->id,
                'name' => 'Visa fórmula diaria',
                'type' => 'credit_card',
                'amount' => '20000.00',
                'frequency' => 'monthly',
                'next_due_date' => '2026-12-10',
                'is_active' => true,
                'current_balance' => '20000.00',
                'interest_rate' => '40.000',
            ]);
            $debt = Debt::query()->create([
                'user_id' => $user->id,
                'commitment_id' => $commitment->id,
                'name' => $commitment->name,
                'kind' => 'credit_card',
                'outstanding_amount' => '20000.00',
                'minimum_payment' => '0.00',
                'minimum_payment_confirmed' => false,
            ]);
            CreditCardStatement::query()->create([
                'user_id' => $user->id,
                'commitment_id' => $commitment->id,
                'debt_id' => $debt->id,
                'statement_date' => '2026-10-01',
                'closing_balance' => '20000.00',
                'due_date' => '2026-10-31',
            ]);

            $this->withToken($token)->getJson('/api/v1/interests/cut')
                ->assertOk()
                ->assertJsonPath('data.period.days_elapsed', 30)
                ->assertJsonPath('data.items.0.financing_interest', '657.53')
                ->assertJsonPath('data.summary.total', '657.53');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_card_financing_interest_stays_zero_until_statement_due_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00'));

        try {
            $user = User::factory()->create();
            UserPreference::query()->create([
                'user_id' => $user->id,
                'currency' => 'DOP',
                'timezone' => 'America/Santo_Domingo',
                'reminders' => 3,
                'week_starts' => 'Monday',
                'appearance' => 'system',
                'pay_frequency' => 'monthly',
            ]);
            $token = $user->createToken('test-token')->plainTextToken;
            $account = Account::query()->create([
                'user_id' => $user->id,
                'name' => 'Visa en gracia',
                'type' => 'credit_card',
                'currency' => 'DOP',
                'is_active' => true,
            ]);
            $commitment = Commitment::query()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'name' => 'Visa en gracia',
                'type' => 'credit_card',
                'amount' => '20000.00',
                'frequency' => 'monthly',
                'next_due_date' => '2026-11-30',
                'is_active' => true,
                'current_balance' => '20000.00',
                'statement_balance' => '20000.00',
                'interest_rate' => '40.000',
            ]);
            $debt = Debt::query()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'commitment_id' => $commitment->id,
                'name' => $commitment->name,
                'kind' => 'credit_card',
                'outstanding_amount' => '20000.00',
                'statement_balance' => '20000.00',
                'minimum_payment' => '1000.00',
                'minimum_payment_confirmed' => true,
                'due_date' => '2026-11-30',
                'statement_day' => 5,
                'payment_grace_days' => 25,
            ]);
            $commitment->update(['debt_id' => $debt->id]);

            $this->withToken($token)->getJson('/api/v1/interests/cut')
                ->assertOk()
                ->assertJsonPath('data.items.0.financing_interest', '0.00')
                ->assertJsonPath('data.items.0.financing_status', 'grace_period')
                ->assertJsonPath('data.items.0.statement_balance', '20000.00')
                ->assertJsonPath('data.items.0.projected_financed_balance', '20000.00')
                ->assertJsonPath('data.items.0.daily_interest_if_unpaid', '21.92')
                ->assertJsonPath('data.items.0.financing_starts_on', '2026-12-01')
                ->assertJsonPath('data.summary.card_financing_interest', '0.00');
        } finally {
            Carbon::setTestNow();
        }
    }

    private function movement(int $userId, int $debtId, string $kind, string $delta, string $occurredAt): void
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