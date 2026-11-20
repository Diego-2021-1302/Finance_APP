<?php

namespace App\Services\TariffConnectors;

use Illuminate\Validation\ValidationException;

class TariffValidationService
{
    public function validate(array $normalizedItem): bool
    {
        if (!isset($normalizedItem['fee_type'], $normalizedItem['value'])) {
            return false;
        }

        $value = (string) $normalizedItem['value'];
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return false;
        }
        if (bccomp($value, '0.00', 4) < 0) {
            return false;
        }

        return true;
    }
}
