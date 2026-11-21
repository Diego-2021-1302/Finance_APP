<?php

namespace App\Services;

use App\Models\CommitmentOccurrence;
use App\Models\User;
use Illuminate\Support\Collection;

class PaymentPriorityService
{
    public function priorities(User $user): Collection
    {
        $today = now()->startOfDay();
        $occurrences = CommitmentOccurrence::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'paid')
            ->with(['commitment', 'payments'])
            ->orderBy('due_date')
            ->get();

        return $occurrences->map(function ($occ) use ($today) {
            $paid = (string) $occ->payments->whereNull('reversed_at')->sum('amount');
            $remaining = bcsub((string) $occ->amount_due, $paid, 2);
            $daysRemaining = (int) $today->diffInDays($occ->due_date, false);

            $urgency = 'normal';
            $reason = '';

            if ($daysRemaining < 0) {
                $urgency = 'critical';
                $reason = sprintf('Este pago está vencido por %d días y tiene un saldo pendiente de %s.', abs($daysRemaining), $remaining);
            } elseif ($daysRemaining === 0) {
                $urgency = 'critical';
                $reason = sprintf('Este compromiso vence hoy. Importe pendiente: %s.', $remaining);
            } elseif ($daysRemaining <= 3) {
                $urgency = 'high';
                $reason = sprintf('Vence en %d días. Importe pendiente: %s.', $daysRemaining, $remaining);
            } else {
                $reason = sprintf('Vence el %s. Importe pendiente: %s.', $occ->due_date->format('M j'), $remaining);
            }

            $type = $occ->commitment?->type ?? 'other';
            $rate = max(0, (float) ($occ->commitment?->interest_rate ?? 0));

            return [
                'commitment_id' => $occ->commitment_id,
                'occurrence_id' => $occ->id,
                'name' => $occ->commitment?->name ?? 'Compromiso',
                'type' => $type,
                'due_date' => $occ->due_date->toDateString(),
                'days_remaining' => $daysRemaining,
                'amount_due' => $remaining,
                'annual_interest_rate' => number_format($rate, 3, '.', ''),
                'urgency' => $urgency,
                'reason' => $reason,
            ];
        })->sort(function ($a, $b) {
            // Overdue and critical urgency first
            $urgencyWeight = ['critical' => 0, 'high' => 1, 'normal' => 2];
            $uA = $urgencyWeight[$a['urgency']] ?? 2;
            $uB = $urgencyWeight[$b['urgency']] ?? 2;
            if ($uA !== $uB) return $uA <=> $uB;

            // Earliest due date
            if ($a['days_remaining'] !== $b['days_remaining']) {
                return $a['days_remaining'] <=> $b['days_remaining'];
            }

            // Higher interest rate next
            return (float) $b['annual_interest_rate'] <=> (float) $a['annual_interest_rate'];
        })->values();
    }
}
