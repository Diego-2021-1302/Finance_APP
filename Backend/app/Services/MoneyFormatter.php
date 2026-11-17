<?php

namespace App\Services;

class MoneyFormatter
{
    public function dop(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        $digits = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '00');
        $whole = ltrim($whole, '0') ?: '0';
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;
        return ($negative ? '−' : '').'RD$ '.$whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
