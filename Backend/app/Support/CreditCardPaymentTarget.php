<?php

namespace App\Support;

final class CreditCardPaymentTarget
{
    public static function amount(
        string $mode,
        string $statementBalance,
        ?string $minimumPayment,
        bool $minimumConfirmed,
    ): string {
        $amount = $mode === 'minimum'
            ? ($minimumConfirmed ? ($minimumPayment ?? '0.00') : '0.00')
            : $statementBalance;

        return bccomp($amount, '0.00', 2) > 0 ? $amount : '0.00';
    }
}