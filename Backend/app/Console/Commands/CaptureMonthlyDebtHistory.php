<?php

namespace App\Console\Commands;

use App\Models\Debt;
use App\Models\MonthlyDebtHistory;
use App\Models\User;
use Illuminate\Console\Command;

class CaptureMonthlyDebtHistory extends Command
{
    protected $signature = 'finance:capture-debt-history {--month= : YYYY-MM to capture}';
    protected $description = 'Capture one verified month-end debt balance snapshot per user';

    public function handle(): int
    {
        $month = $this->option('month') ?: now()->format('Y-m');
        $capturedAt = $month === now()->format('Y-m')
            ? now()->toDateString()
            : (now()->createFromFormat('Y-m', $month)->endOfMonth()->toDateString());

        User::query()->chunkById(100, function ($users) use ($month, $capturedAt) {
            foreach ($users as $user) {
                $debts = Debt::query()->where('user_id', $user->id)->get();
                $card = $debts->where('kind', 'credit_card')->sum(fn ($debt) => (float) $debt->outstanding_amount);
                $loan = $debts->where('kind', 'loan')->sum(fn ($debt) => (float) $debt->outstanding_amount);
                $other = $debts->whereNotIn('kind', ['credit_card', 'loan'])->sum(fn ($debt) => (float) $debt->outstanding_amount);

                MonthlyDebtHistory::query()->updateOrCreate(
                    ['user_id' => $user->id, 'month' => $month],
                    [
                        'total_debt' => number_format($card + $loan + $other, 2, '.', ''),
                        'card_debt' => number_format($card, 2, '.', ''),
                        'loan_debt' => number_format($loan, 2, '.', ''),
                        'other_debt' => number_format($other, 2, '.', ''),
                        'captured_at' => $capturedAt,
                        'source' => 'month_end',
                    ]
                );
            }
        });

        $this->info("Captured debt history for {$month}");
        return self::SUCCESS;
    }
}
