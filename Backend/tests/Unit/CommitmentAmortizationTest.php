<?php

namespace Tests\Unit;

use App\Actions\RecalculateCommitmentAmortization;
use PHPUnit\Framework\TestCase;

class CommitmentAmortizationTest extends TestCase
{
    public function test_it_calculates_period_interest_from_the_annual_rate(): void
    {
        $amortization = new RecalculateCommitmentAmortization();

        $this->assertSame('12.00', $amortization->interestFor('1200.00', '12.000', 'monthly'));
        $this->assertSame('2.60', $amortization->interestFor('1300.00', '5.200', 'biweekly'));
        $this->assertSame('0.00', $amortization->interestFor('0.00', '12.000', 'monthly'));
    }
}
