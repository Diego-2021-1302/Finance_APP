<?php

namespace Tests\Unit;

use App\Actions\RecalculateCommitmentAmortization;
use App\Models\Account;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanAmortizationRecalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_loan_amortization_recalculation_respects_past_payments_and_accrues_interest(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'DOP',
        ]);
        $commitment = Commitment::query()->create([
            'user_id' => $user->id,
            'name' => 'Loan 1',
            'type' => 'loan',
            'amount' => '1000.00',
            'frequency' => 'monthly',
            'next_due_date' => '2026-01-15',
            'is_active' => true,
            'opening_balance' => '10000.00',
            'current_balance' => '10000.00',
            'interest_rate' => '12.000',
            'institution' => 'Bank',
            'reference_number' => 'LN-001',
            'maturity_date' => '2027-01-15',
        ]);

        $occ1 = CommitmentOccurrence::query()->create([
            'user_id' => $user->id,
            'commitment_id' => $commitment->id,
            'amount_due' => '1000.00',
            'due_date' => '2026-01-15',
            'status' => 'paid',
        ]);

        Payment::query()->create([
            'user_id' => $user->id,
            'commitment_occurrence_id' => $occ1->id,
            'account_id' => $account->id,
            'amount' => '1000.00',
            'paid_at' => '2026-01-15 10:00:00',
            'idempotency_key' => '123e4567-e89b-12d3-a456-426614174099',
        ]);

        $action = new RecalculateCommitmentAmortization();
        $action->execute($commitment);

        $this->assertNotNull($occ1->fresh()->interest_accrued);
        $this->assertEquals('9100.00', $commitment->fresh()->current_balance);
    }
}
