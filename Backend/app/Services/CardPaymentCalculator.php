<?php

namespace App\Services;

use InvalidArgumentException;

class CardPaymentCalculator
{
    public static function calculate(
        string $outstanding,
        string $statementBalance,
        string $minimumPayment,
        string $paymentType,
        string $customAmount = '0.00'
    ): array {
        $amount = match ($paymentType) {
            'total' => $outstanding,
            'statement' => $statementBalance,
            'minimum' => $minimumPayment,
            'custom' => $customAmount,
            default => throw new InvalidArgumentException('Tipo de pago no válido.'),
        };

        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new InvalidArgumentException('El pago seleccionado no tiene un monto disponible.');
        }
        if (bccomp($amount, $outstanding, 2) > 0) {
            throw new InvalidArgumentException('El pago no puede exceder el balance a la fecha.');
        }

        $newOutstanding = bcsub($outstanding, $amount, 2);
        $newStatement = bccomp($statementBalance, $amount, 2) >= 0
            ? bcsub($statementBalance, $amount, 2)
            : '0.00';

        return [
            'amount' => $amount,
            'outstanding' => $newOutstanding,
            'statement_balance' => $newStatement,
        ];
    }
}
