<?php

namespace App\Services;

class CashFlowProjection
{
    private static function cents(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'), 2);
        return ($negative ? -1 : 1) * ((int) $parts[0] * 100 + (int) str_pad(substr($parts[1] ?? '', 0, 2), 2, '0'));
    }

    private static function money(int $value): string
    {
        return ($value < 0 ? '-' : '') . intdiv(abs($value), 100) . '.' . str_pad((string) (abs($value) % 100), 2, '0', STR_PAD_LEFT);
    }

    public function calculate(string $opening, array $obligations, array $incomes, string $today): array
    {
        $events = [];
        $incomeTotal = $dueTotal = 0;
        foreach ($incomes as $income) {
            $amount = self::cents($income['remaining']);
            $incomeTotal += $amount;
            $events[] = ['date' => $income['date'], 'kind' => 'income', 'name' => $income['source'], 'amount' => $amount];
        }
        foreach ($obligations as $item) {
            $amount = self::cents($item['amount_due']);
            $dueTotal += $amount;
            $events[] = ['date' => max($today, $item['due_date']), 'due_date' => $item['due_date'], 'kind' => 'payment', 'name' => $item['name'], 'amount' => -$amount, 'occurrence_id' => $item['occurrence_id']];
        }
        $eventKindsByDate = [];
        foreach ($events as $event) {
            $eventKindsByDate[$event['date']][$event['kind']] = true;
        }
        foreach ($events as &$event) {
            $event['timing_uncertain'] = isset($eventKindsByDate[$event['date']]['income'], $eventKindsByDate[$event['date']]['payment']);
        }
        unset($event);
        // Conservative: payments before unconfirmed receipts on the same day.
        usort($events, fn ($a, $b) => strcmp($a['date'], $b['date']) ?: ($a['amount'] < 0 ? 0 : 1) <=> ($b['amount'] < 0 ? 0 : 1) ?: strcmp($a['due_date'] ?? '', $b['due_date'] ?? '') ?: (($a['occurrence_id'] ?? 0) <=> ($b['occurrence_id'] ?? 0)));
        $balance = self::cents($opening);
        $lowest = $balance;
        $firstShortfall = $balance < 0 ? $today : null;
        foreach ($events as &$event) {
            $balance += $event['amount'];
            $lowest = min($lowest, $balance);
            if ($balance < 0 && $firstShortfall === null) $firstShortfall = $event['date'];
            $event['amount'] = self::money($event['amount']);
            $event['balance'] = self::money($balance);
        }
        unset($event);
        return [
            'expected_total' => self::money($incomeTotal),
            'commitments_total' => self::money($dueTotal),
            'ending_balance' => self::money($balance),
            'minimum_projected_balance' => self::money($lowest),
            'free_to_spend' => self::money(max(0, $lowest)),
            'peak_shortfall' => self::money(max(0, -$lowest)),
            'first_shortfall_date' => $firstShortfall,
            'events' => $events,
        ];
    }
}
