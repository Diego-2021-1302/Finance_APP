<?php

namespace App\Console\Commands;

use App\Models\Income;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateIncomeOccurrences extends Command
{
    protected $signature = 'finance:generate-income-occurrences';
    protected $description = 'Generate pending recurring income occurrences up to the planning horizon';

    public function handle(): int
    {
        $horizon = now()->addDays(90)->startOfDay();

        Income::query()
            ->whereNotNull('recurrence_key')
            ->where('frequency', '!=', 'once')
            ->where(function ($query) {
                $query->whereNull('recurrence_end_date')->orWhereDate('recurrence_end_date', '>=', now());
            })
            ->chunkById(100, function ($templates) use ($horizon) {
                foreach ($templates as $template) {
                    $cursor = Carbon::parse($template->expected_date)->startOfDay();
                    $today = now()->startOfDay();

                    while ($cursor->lte($horizon)) {
                        if ($cursor->gte($today) && (!$template->recurrence_end_date || $cursor->lte(Carbon::parse($template->recurrence_end_date)))) {
                            Income::query()->firstOrCreate([
                                'recurrence_key' => $template->recurrence_key,
                                'expected_date' => $cursor->toDateString(),
                            ], [
                                'user_id' => $template->user_id,
                                'source' => $template->source,
                                'amount' => $template->amount,
                                'frequency' => $template->frequency,
                                'recurrence_end_date' => $template->recurrence_end_date,
                                'recurrence_day' => $template->recurrence_day,
                                'recurrence_second_day' => $template->recurrence_second_day,
                                'notes' => $template->notes,
                            ]);
                        }

                        $next = $this->nextDate($cursor, $template);
                        if (!$next || $next->equalTo($cursor)) break;
                        $cursor = $next;
                    }
                }
            });

        $this->info('Income occurrences generated successfully.');
        return self::SUCCESS;
    }

    private function nextDate(Carbon $current, Income $template): ?Carbon
    {
        return match ($template->frequency) {
            'weekly' => $current->copy()->addWeek(),
            'biweekly' => $current->copy()->addDays(14),
            'semimonthly' => $this->nextSemimonthlyDate($current, (int) $template->recurrence_day, (int) $template->recurrence_second_day),
            'monthly' => $current->copy()->addMonthNoOverflow()->day(min((int) $template->recurrence_day, $current->copy()->addMonthNoOverflow()->daysInMonth)),
            default => null,
        };
    }

    private function nextSemimonthlyDate(Carbon $current, int $firstDay, int $lastDay): Carbon
    {
        $first = $current->copy()->day(min($firstDay ?: 15, $current->daysInMonth));
        $monthEnd = $current->copy()->endOfMonth();
        $second = $current->copy()->day(min($lastDay ?: $current->daysInMonth, $current->daysInMonth));

        if ($current->lt($first)) return $first;
        if ($current->lt($second)) return $second;
        return $current->copy()->addMonthNoOverflow()->startOfMonth()->day(min($firstDay ?: 15, $current->copy()->addMonthNoOverflow()->daysInMonth));
    }
}
