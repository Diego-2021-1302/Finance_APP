<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\Debt;
use App\Models\Income;
use App\Models\Reserve;
use Illuminate\Support\Carbon;

class FinancialPlanningService
{
    public function __construct(private readonly CashFlowProjection $projection) {}

    public function calculate(int $userId, int $days): array
    {
        $today = now()->startOfDay();
        $end = $today->copy()->addDays($days);

        $accounts = Account::query()->where('user_id', $userId)->where('is_active', true)->where('currency', 'DOP')->whereNotIn('type', ['credit_card'])->get();
        $liquid = '0.00';
        foreach ($accounts as $account) {
            $liquid = bcadd($liquid, (string) $account->transactions()->sum('amount'), 2);
        }

        $reserved = '0.00';
        foreach (Reserve::query()->where('user_id', $userId)->get(['current_amount']) as $reserve) {
            $reserved = bcadd($reserved, (string) $reserve->current_amount, 2);
        }
        $available = bccomp($liquid, $reserved, 2) > 0 ? bcsub($liquid, $reserved, 2) : '0.00';

        $obligations = [];
        $missing = [];
        $occurrences = CommitmentOccurrence::query()
            ->where('user_id', $userId)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', '<=', $end->toDateString())
            ->whereHas('commitment', fn($query) => $query->where('is_active', true))
            ->with(['commitment', 'payments'])
            ->orderBy('due_date')
            ->get();

        $minimumModeCards = Commitment::query()
            ->where('user_id', $userId)
            ->where('type', 'credit_card')
            ->where('card_payment_mode', 'minimum')
            ->where('is_active', true)
            ->whereDate('next_due_date', '<=', $end->toDateString())
            ->with('debt')
            ->get();
        foreach ($minimumModeCards as $card) {
            if (!$card->debt?->minimum_payment_confirmed || bccomp((string) ($card->debt?->minimum_payment ?? '0.00'), '0.00', 2) <= 0) {
                $missing[] = 'Pago mínimo no confirmado para ' . $card->name;
            }
        }

        foreach ($occurrences as $occurrence) {
            $paid = '0.00';
            foreach ($occurrence->payments->whereNull('reversed_at') as $payment) {
                $paid = bcadd($paid, (string) $payment->amount, 2);
            }
            $pending = bcsub((string) $occurrence->amount_due, $paid, 2);
            if (bccomp($pending, '0.00', 2) <= 0) continue;

            $commitment = $occurrence->commitment;
            $minimum = $pending;
            $minimumConfirmed = true;
            if ($commitment?->type === 'credit_card') {
                $debt = Debt::query()->where('user_id', $userId)->where('commitment_id', $commitment->id)->first();
                $minimumConfirmed = (bool) ($debt?->minimum_payment_confirmed);
                $minimum = $minimumConfirmed ? (string) ($debt?->minimum_payment ?? '0.00') : '0.00';
            }

            $obligations[] = $this->serializeObligation(
                'commitment-occurrence:' . $occurrence->id,
                'commitment',
                $commitment?->name ?? 'Compromiso',
                $occurrence->due_date->toDateString(),
                (string) $occurrence->amount_due,
                $pending,
                $minimum,
                $minimumConfirmed,
                $commitment?->type ?? 'other'
            );
        }

        $standaloneDebts = Debt::query()->where('user_id', $userId)->whereNull('commitment_id')->where('outstanding_amount', '>', 0)->get();
        foreach ($standaloneDebts as $debt) {
            $target = '0.00';
            $minimumConfirmed = false;
            if ($debt->kind === 'loan' && bccomp((string) ($debt->minimum_payment ?? '0.00'), '0.00', 2) > 0) {
                $target = (string) $debt->minimum_payment;
                $minimumConfirmed = true;
            } elseif ($debt->kind === 'credit_card') {
                if ($debt->minimum_payment_confirmed) {
                    $target = (string) ($debt->minimum_payment ?? '0.00');
                    $minimumConfirmed = true;
                } else {
                    $missing[] = 'Pago mínimo no confirmado para ' . $debt->name;
                }
            } else {
                $missing[] = 'Vencimiento o cuota exigible no disponible para ' . $debt->name;
            }

            if (!$debt->due_date || bccomp($target, '0.00', 2) <= 0) {
                $missing[] = 'Vencimiento o cuota exigible no disponible para ' . $debt->name;
                continue;
            }

            $obligations[] = $this->serializeObligation(
                'debt:' . $debt->id,
                'debt',
                $debt->name,
                $debt->due_date->toDateString(),
                (string) $debt->outstanding_amount,
                $target,
                $target,
                $minimumConfirmed,
                $debt->kind
            );
        }

        usort($obligations, fn($a, $b) => strcmp($a['due_date'], $b['due_date']) ?: strcmp($a['id'], $b['id']));

        $incomeOccurrences = Income::query()->where('user_id', $userId)->whereBetween('expected_date', [$today->toDateString(), $end->toDateString()])->with('payments')->orderBy('expected_date')->get();
        $incomeSources = [];
        $expectedIncome = '0.00';
        foreach ($incomeOccurrences as $income) {
            $received = '0.00';
            foreach ($income->payments->whereNull('reversed_at') as $payment) {
                $received = bcadd($received, (string) $payment->amount, 2);
            }
            $remaining = bcsub((string) $income->amount, $received, 2);
            if (bccomp($remaining, '0.00', 2) <= 0) continue;
            $expectedIncome = bcadd($expectedIncome, $remaining, 2);
            $incomeSources[] = [
                'id' => 'income:' . $income->id,
                'type' => 'expected_income',
                'date' => $income->expected_date->toDateString(),
                'account_id' => null,
                'currency' => 'DOP',
                'amount_original' => $remaining,
                'amount_remaining' => $remaining,
                'name' => $income->source,
            ];
        }

        $fundingSources = [[
            'id' => 'current-balance',
            'type' => 'current_balance',
            'date' => $today->toDateString(),
            'account_id' => null,
            'currency' => 'DOP',
            'amount_original' => $available,
            'amount_remaining' => $available,
            'name' => 'Saldo líquido utilizable',
        ], ...$incomeSources];

        $allocations = [];
        foreach ($obligations as &$obligation) {
            $remaining = $obligation['amount_pending'];
            $assignedCurrent = '0.00';
            $assignedFuture = '0.00';

            $sourceIndexes = array_values(array_filter(array_keys($fundingSources), fn($index) => $fundingSources[$index]['type'] !== 'current_balance'));
            $sourceIndexes[] = 0;
            foreach ($sourceIndexes as $sourceIndex) {
                $source = &$fundingSources[$sourceIndex];
                if (strcmp($source['date'], $obligation['due_date']) > 0 || bccomp($remaining, '0.00', 2) <= 0) continue;
                $sourceRemaining = $source['amount_remaining'];
                $assigned = bccomp($sourceRemaining, $remaining, 2) >= 0 ? $remaining : $sourceRemaining;
                if (bccomp($assigned, '0.00', 2) <= 0) continue;
                $source['amount_remaining'] = bcsub($sourceRemaining, $assigned, 2);
                $remaining = bcsub($remaining, $assigned, 2);
                if ($source['type'] === 'current_balance') $assignedCurrent = bcadd($assignedCurrent, $assigned, 2);
                else $assignedFuture = bcadd($assignedFuture, $assigned, 2);

                $allocations[] = [
                    'id' => $obligation['id'] . ':' . $source['id'],
                    'obligation_id' => $obligation['id'],
                    'source_id' => $source['id'],
                    'amount' => $assigned,
                    'availability_date' => $source['date'],
                    'due_date' => $obligation['due_date'],
                    'coverage_type' => $source['type'] === 'current_balance' ? 'current' : 'expected_income',
                    'status' => 'projected',
                ];
            }
            unset($source);

            $obligation['funds_current_assigned'] = $assignedCurrent;
            $obligation['funds_future_assigned'] = $assignedFuture;
            $obligation['shortfall'] = $remaining;
            $obligation['coverage_status'] = bccomp($remaining, '0.00', 2) === 0
                ? 'covered'
                : (bccomp(bcadd($assignedCurrent, $assignedFuture, 2), '0.00', 2) > 0 ? 'partially_covered' : 'uncovered');
        }
        unset($obligation);

        $projectionItems = array_map(function ($obligation) {
            return [
                'occurrence_id' => $obligation['occurrence_id'],
                'name' => $obligation['name'],
                'due_date' => $obligation['due_date'],
                'amount_due' => $obligation['amount_pending'],
            ];
        }, array_filter($obligations, fn($obligation) => isset($obligation['occurrence_id'])));

        $projectionIncome = array_map(fn($source) => ['source' => $source['name'], 'date' => $source['date'], 'remaining' => $source['amount_original']], $incomeSources);
        $projection = $this->projection->calculate($available, array_values($projectionItems), $projectionIncome, $today->toDateString());

        $pendingTotal = '0.00';
        $uncoveredTotal = '0.00';
        foreach ($obligations as $obligation) {
            $pendingTotal = bcadd($pendingTotal, $obligation['amount_pending'], 2);
            $uncoveredTotal = bcadd($uncoveredTotal, $obligation['shortfall'], 2);
        }

        $status = count($missing) === 0 ? 'complete' : 'incomplete';
        $recommendations = [];
        if (bccomp($projection['peak_shortfall'], '0.00', 2) > 0) {
            $recommendations[] = 'Existe un faltante proyectado de ' . $projection['peak_shortfall'] . '. Protege fondos antes del primer vencimiento descubierto.';
        } elseif (count($obligations) > 0) {
            $recommendations[] = 'Los pagos registrados tienen una cobertura proyectada dentro de este horizonte.';
        }

        return [
            'currency' => 'DOP',
            'days' => $days,
            'as_of' => $today->toDateString(),
            'data_completeness' => ['status' => $status, 'missing_information' => array_values(array_unique($missing))],
            'account_balance' => $liquid,
            'virtual_reserves' => $reserved,
            'allocatable_now' => $available,
            'free_after_plan' => $projection['free_to_spend'],
            'funding_sources' => $fundingSources,
            'obligations' => $obligations,
            'allocations' => $allocations,
            'expected_incomes' => array_map(fn($source) => ['source' => $source['name'], 'date' => $source['date'], 'remaining' => $source['amount_original']], $incomeSources),
            'projection' => $projection,
            'summary' => [
                'expected_income' => $expectedIncome,
                'pending_obligations' => $pendingTotal,
                'maximum_shortfall' => $projection['peak_shortfall'],
                'projected_closing_balance' => $projection['ending_balance'],
                'uncovered_total' => $uncoveredTotal,
            ],
            'recommendations' => $recommendations,
            'warnings' => [
                'Los ingresos previstos no son efectivo disponible hasta su recepción.',
                'Las asignaciones son sugerencias proyectadas y no reservas bancarias.',
                'Las monedas distintas de DOP no se incluyen sin conversión válida.',
            ],
        ];
    }

    private function serializeObligation(string $id, string $sourceType, string $name, string $dueDate, string $original, string $pending, string $minimum, bool $minimumConfirmed, string $type): array
    {
        $days = (int) now()->startOfDay()->diffInDays(Carbon::parse($dueDate), false);
        return [
            'id' => $id,
            'occurrence_id' => str_starts_with($id, 'commitment-occurrence:') ? (int) substr($id, strrpos($id, ':') + 1) : null,
            'commitment_id' => null,
            'name' => $name,
            'type' => $type,
            'source_type' => $sourceType,
            'due_date' => $dueDate,
            'overdue' => $days < 0,
            'amount_original' => $original,
            'amount_due' => $pending,
            'amount_pending' => $pending,
            'minimum_required' => $minimum,
            'minimum_confirmed' => $minimumConfirmed,
            'funds_current_assigned' => '0.00',
            'funds_future_assigned' => '0.00',
            'shortfall' => $pending,
            'coverage_status' => 'uncovered',
            'priority' => $days < 0 ? 'critical' : ($days <= 7 ? 'high' : 'normal'),
            'reason' => $days < 0 ? 'Pago vencido.' : 'Vencimiento dentro del horizonte.',
            'currency' => 'DOP',
        ];
    }
}
