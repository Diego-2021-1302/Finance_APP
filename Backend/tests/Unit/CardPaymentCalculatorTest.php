<?php

namespace Tests\Unit;

use App\Services\CardPaymentCalculator;
use PHPUnit\Framework\TestCase;

class CardPaymentCalculatorTest extends TestCase
{
    public function test_total_reduces_current_and_statement_balances(): void
    {
        $result = CardPaymentCalculator::calculate('55184.56', '44323.11', '2000.00', 'total');
        self::assertSame('55184.56', $result['amount']);
        self::assertSame('0.00', $result['outstanding']);
        self::assertSame('0.00', $result['statement_balance']);
    }

    public function test_statement_payment_reduces_both_balances(): void
    {
        $result = CardPaymentCalculator::calculate('55184.56', '44323.11', '2000.00', 'statement');
        self::assertSame('44323.11', $result['amount']);
        self::assertSame('10861.45', $result['outstanding']);
        self::assertSame('0.00', $result['statement_balance']);
    }

    public function test_minimum_and_custom_reduce_statement_when_applicable(): void
    {
        $minimum = CardPaymentCalculator::calculate('55184.56', '44323.11', '2000.00', 'minimum');
        self::assertSame('53184.56', $minimum['outstanding']);
        self::assertSame('42323.11', $minimum['statement_balance']);

        $custom = CardPaymentCalculator::calculate('55184.56', '44323.11', '2000.00', 'custom', '5000.00');
        self::assertSame('50184.56', $custom['outstanding']);
        self::assertSame('39323.11', $custom['statement_balance']);
    }
}
