<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialReportAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_statement_and_minimum_are_not_double_counted_in_calendar_or_cash_flow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00'));

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
                'next_due_date' => '2026-03-10',
                'is_active' => true,
            ]);
            $occurrence = CommitmentOccurrence::query()->create([
                'user_id' => $user->id,
                'commitment_id' => $commitment->id,
                'amount_due' => '1000.00',
                'due_date' => '2026-03-10',
                'status' => 'scheduled',
            ]);
            $debt = Debt::query()->create([
                'user_id' => $user->id,
                'account_id' => $cardAccount->id,
                'name' => 'Visa',
                'kind' => 'credit_card',
                'outstanding_amount' => '850.00',
                'minimum_payment' => '90.00',
                'minimum_payment_confirmed' => true,
                'due_date' => '2026-03-10',
                'statement_balance' => '1000.00',
            ]);
            $payment = Payment::query()->create([
                'user_id' => $user->id,
                'commitment_occurrence_id' => $occurrence->id,
                'account_id' => $cashAccount->id,
                'amount' => '100.00',
                'paid_at' => '2026-03-05 12:00:00',
                'idempotency_key' => '123e4567-e89b-12d3-a456-426614174010',
            ]);
            DebtBalanceMovement::query()->create([
                'user_id' => $user->id,
                'debt_id' => $debt->id,
                'payment_id' => $payment->id,
                'account_id' => $cashAccount->id,
                'kind' => 'payment',
                'delta' => '-100.00',
                'description' => 'Commitment payment',
                'occurred_at' => $payment->paid_at,
                'idempotency_key' => Str::uuid()->toString(),
            ]);
            DebtBalanceMovement::query()->create([
                'user_id' => $user->id,
                'debt_id' => $debt->id,
                'account_id' => $cashAccount->id,
                'kind' => 'payment',
                'delta' => '-50.00',
                'description' => 'Direct debt payment',
                'occurred_at' => '2026-03-07 12:00:00',
                'idempotency_key' => Str::uuid()->toString(),
            ]);

            $token = $user->createToken('test-token')->plainTextToken;
            $this->withToken($token)
                ->getJson('/api/v1/calendar?from=2026-03-10&to=2026-03-10')
                ->assertOk()
                ->assertJsonCount(1, 'events')
                ->assertJsonPath('events.0.amount', '90.00')
                ->assertJsonPath('events.0.type', 'debt');

            $this->withToken($token)
                ->getJson('/api/v1/reports/cash-flow?from=2026-03-01&to=2026-03-31')
                ->assertOk()
                ->assertJsonPath('totals.forecast', '-90.00')
                ->assertJsonPath('totals.actual', '-150.00')
                ->assertJsonPath('series.0.forecastMinor', -9000)
                ->assertJsonPath('series.0.actualMinor', -15000);

            $otherUser = User::factory()->create();
            $this->actingAs($otherUser, 'sanctum')
                ->getJson('/api/v1/calendar?from=2026-03-10&to=2026-03-10')
                ->assertOk()
                ->assertJsonCount(0, 'events');
            $this->actingAs($otherUser, 'sanctum')
                ->getJson('/api/v1/reports/cash-flow?from=2026-03-01&to=2026-03-31')
                ->assertOk()
                ->assertJsonPath('totals.forecast', '0.00')
                ->assertJsonPath('totals.actual', '0.00');
        } finally {
            Carbon::setTestNow();
        }
    }
}
