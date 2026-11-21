<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CommitmentOccurrence;
use App\Models\Income;
use App\Models\Debt;
use App\Models\Reserve;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:90']]);
        $days = (int) ($validated['days'] ?? 30);
        $today = now()->startOfDay();
        $end = $today->copy()->addDays($days);
        $userId = $request->user()->id;

        // A credit card is a liability, not cash available for allocating.
        $accounts = Account::query()->where('user_id', $userId)->where('is_active', true)
            ->where('currency', 'DOP')->whereNotIn('type', ['credit_card'])
            ->withSum('transactions', 'amount')->get();
        $cash = round($accounts->sum(fn ($a) => max(0, (float) ($a->transactions_sum_amount ?? 0))), 2);
        $negative = round($accounts->sum(fn ($a) => min(0, (float) ($a->transactions_sum_amount ?? 0))), 2);
        $balance = round($cash + $negative, 2);

        // Reserves are virtual earmarks; do not debit bank balances again.
        $reserved = round((float) Reserve::query()->where('user_id', $userId)->sum('current_amount'), 2);
        $available = max(0, round($balance - $reserved, 2));

        $occurrences = CommitmentOccurrence::query()->where('user_id', $userId)
            ->where('status', '!=', 'paid')->whereDate('due_date', '<=', $end->toDateString())
            ->whereHas('commitment', fn ($q) => $q->where('is_active', true))
            ->with(['commitment', 'payments'])->orderBy('due_date')->get();
        $items = [];
        foreach ($occurrences as $occ) {
            $paid = (float) $occ->payments->whereNull('reversed_at')->sum('amount');
            $remaining = round(max(0, (float) $occ->amount_due - $paid), 2);
            if ($remaining <= 0) continue;
            $items[] = [
                'occurrence_id' => $occ->id,
                'commitment_id' => $occ->commitment_id,
                'name' => $occ->commitment?->name ?? 'Compromiso',
                'type' => $occ->commitment?->type ?? 'other',
                'due_date' => $occ->due_date->toDateString(),
                'overdue' => $occ->due_date->lt($today),
                'amount_due' => number_format($remaining, 2, '.', ''),
            ];
        }
        // Pass 1 protects confirmed minimum payments / scheduled instalments.
        // Pass 2 directs remaining cash to expensive balances, preferring deadlines
        // where paying the statement in full may preserve a grace period.
        $debtIds = collect($items)->pluck('commitment_id')->unique();
        $debts = Debt::query()->where('user_id', $userId)
            ->whereIn('commitment_id', $debtIds)->get()->keyBy('commitment_id');
        foreach ($items as &$item) {
            $commitment = $occurrences->firstWhere('id', $item['occurrence_id'])?->commitment;
            $debt = $debts->get($item['commitment_id']);
            $due = (float) $item['amount_due'];
            $isCard = $item['type'] === 'credit_card';
            $minimumConfirmed = $isCard && $debt && $debt->minimum_payment_confirmed;
            $minimum = $minimumConfirmed ? min($due, max(0, (float) $debt->minimum_payment)) : ($isCard ? 0 : $due);
            $rate = max(0, (float) ($commitment?->interest_rate ?? 0));
            $lateFee = max(0, (float) ($commitment?->late_fee_fixed ?? 0));
            $latePercent = max(0, (float) ($commitment?->late_fee_percentage ?? 0));
            $item['minimum_required'] = number_format($minimum, 2, '.', '');
            $item['minimum_confirmed'] = !$isCard || (bool) $minimumConfirmed;
            $item['annual_interest_rate'] = number_format($rate, 3, '.', '');
            // Illustrative simple interest over 30 days; not a bank statement charge.
            $item['estimated_30_day_interest_if_unpaid'] = number_format(round($due * $rate / 100 * 30 / 365, 2), 2, '.', '');
            $item['estimated_late_fee'] = number_format(round($lateFee + $due * $latePercent / 100, 2), 2, '.', '');
            $item['suggested_reserve'] = '0.00';
            $item['reason'] = '';
        }
        unset($item);

        // Priority of minimums: overdue, due date, then potential late charges.
        usort($items, fn ($a, $b) => (int) $b['overdue'] <=> (int) $a['overdue']
            ?: strcmp($a['due_date'], $b['due_date'])
            ?: ((float) $b['estimated_late_fee'] <=> (float) $a['estimated_late_fee']));
        $left = $available;
        foreach ($items as &$item) {
            $assigned = min($left, (float) $item['minimum_required']);
            $item['suggested_reserve'] = number_format($assigned, 2, '.', '');
            $left = round(max(0, $left - $assigned), 2);
            $item['reason'] = $assigned < (float) $item['minimum_required']
                ? 'Fondos insuficientes para cubrir el pago exigible.'
                : ($assigned > 0 ? 'Cubrir pago mínimo o cuota antes del vencimiento.' : 'Pendiente de evaluación de saldo adicional.');
        }
        unset($item);

        // Additional allocation: cards with full-statement opportunity first,
        // then higher annual financing rate, then earlier due dates.
        usort($items, fn ($a, $b) => (int) ($b['type'] === 'credit_card') <=> (int) ($a['type'] === 'credit_card')
            ?: ((float) $b['annual_interest_rate'] <=> (float) $a['annual_interest_rate'])
            ?: strcmp($a['due_date'], $b['due_date']));
        foreach ($items as &$item) {
            $gap = max(0, (float) $item['amount_due'] - (float) $item['suggested_reserve']);
            $extra = min($left, $gap);
            $item['suggested_reserve'] = number_format((float) $item['suggested_reserve'] + $extra, 2, '.', '');
            $item['shortfall'] = number_format(max(0, $gap - $extra), 2, '.', '');
            $left = round(max(0, $left - $extra), 2);
            if ($extra > 0) {
                $item['reason'] = $item['type'] === 'credit_card'
                    ? 'Priorizar saldo facturado para reducir posible financiamiento; confirmar reglas del banco.'
                    : 'Cubrir importe pendiente; comparar costos y penalidades reales.';
            }
        }
        unset($item);
        usort($items, fn ($a, $b) => strcmp($a['due_date'], $b['due_date']) ?: ($a['occurrence_id'] <=> $b['occurrence_id']));

        $incomes = Income::query()->where('user_id', $userId)
            ->whereBetween('expected_date', [$today->toDateString(), $end->toDateString()])
            ->with('payments')->orderBy('expected_date')->get();
        $expected = $incomes->map(function ($income) {
            $received = (float) $income->payments->whereNull('reversed_at')->sum('amount');
            return [
                'source' => $income->source,
                'date' => $income->expected_date->toDateString(),
                'remaining' => number_format(max(0, (float) $income->amount - $received), 2, '.', ''),
            ];
        })->filter(fn ($i) => (float) $i['remaining'] > 0)->values();

        return response()->json(['data' => [
            'currency' => 'DOP', 'days' => $days,
            'account_balance' => number_format($balance, 2, '.', ''),
            'virtual_reserves' => number_format($reserved, 2, '.', ''),
            'allocatable_now' => number_format($available, 2, '.', ''),
            'suggested_total' => number_format($available - $left, 2, '.', ''),
            'free_after_plan' => number_format($left, 2, '.', ''),
            'uncovered_total' => number_format(array_sum(array_map(fn ($i) => (float) $i['shortfall'], $items)), 2, '.', ''),
            'obligations' => $items, 'expected_incomes' => $expected,
            'warnings' => [
                'Solo se incluyen cuentas en DOP; no se convierten otras monedas.',
                'Las reservas virtuales se descuentan del dinero asignable, no del saldo bancario.',
                'Los ingresos futuros no se incluyen como dinero disponible.',
                'Los compromisos sin ocurrencia confirmada no se estiman automáticamente.',
                'La recomendación es orientativa: no incluye gastos esenciales futuros ni calcula penalidades contractuales completas.',
                'Si una tarjeta no tiene pago mínimo confirmado, no se supone uno: compruébalo en el estado de cuenta.',
                'El interés mostrado es una estimación simple de 30 días (base 365), no un cargo automático ni una garantía de ahorro.',
                'Los pagos y reservas son propuestas; confirma las condiciones bancarias antes de actuar.',
            ],
        ]]);
    }
}
