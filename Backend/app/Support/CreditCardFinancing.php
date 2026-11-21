<?php

namespace App\Support;

use InvalidArgumentException;

/** Estimates simple financing on an explicitly confirmed unpaid balance.
 * Not a statement posting engine: actual bank rules may vary.
 */
final class CreditCardFinancing
{
    public static function estimate(string $unpaidPrincipal, string $annualPercent, int $days, int $dayBasis = 365): array
    {
        if (!in_array($dayBasis, [360, 365], true) || $days < 0 || !is_numeric($unpaidPrincipal) || !is_numeric($annualPercent)
            || bccomp($unpaidPrincipal, '0', 6) < 0 || bccomp($annualPercent, '0', 6) < 0
            || bccomp($annualPercent, '100', 6) > 0) {
            throw new InvalidArgumentException('Invalid financing parameters');
        }
        $dailyRate = bcdiv(bcdiv($annualPercent, '100', 12), (string) $dayBasis, 12);
        $charge = bcmul(bcmul($unpaidPrincipal, $dailyRate, 12), (string) $days, 8);
        return [
            'daily_rate' => $dailyRate,
            'monthly_rate' => bcdiv($annualPercent, '1200', 12),
            'estimated_interest' => number_format(round((float) $charge, 2), 2, '.', ''),
            'day_basis' => $dayBasis,
        ];
    }
}
