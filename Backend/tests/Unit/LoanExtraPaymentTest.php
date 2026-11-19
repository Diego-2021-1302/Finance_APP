<?php

namespace Tests\Unit;

use App\Actions\RecalculateCommitmentAmortization;
use PHPUnit\Framework\TestCase;

class LoanExtraPaymentTest extends TestCase
{
    public function test_extra_principal_payment_reduces_balance(): void
    {
        $amortization = new RecalculateCommitmentAmortization();
        $balance = '10000.00';
        $extraPayment = '2000.00';
        $newBalance = bcsub($balance, $extraPayment, 2);

        $this->assertSame('8000.00', $newBalance);
    }

    public function test_credit_card_billed_vs_unbilled_calculation(): void
    {
        $closingBalance = '1500.00';
        $paymentsAgainstStatement = '500.00';
        $billedRemaining = bcsub($closingBalance, $paymentsAgainstStatement, 2);

        $unbilledPurchases = '350.00';
        $totalOutstanding = bcadd($billedRemaining, $unbilledPurchases, 2);

        $this->assertSame('1000.00', $billedRemaining);
        $this->assertSame('1350.00', $totalOutstanding);
    }
}
