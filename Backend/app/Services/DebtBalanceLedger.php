<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DebtBalanceLedger
{
    public function ensureOpeningBalance(
        Debt $debt,
        ?int $accountId,
        Carbon $occurredAt,
        string $description = 'Opening balance at card history activation',
    ): void {
        if (DebtBalanceMovement::query()->where('debt_id', $debt->id)->exists()) {
            return;
        }

        DebtBalanceMovement::query()->create([
            'user_id' => $debt->user_id,
            'debt_id' => $debt->id,
            'account_id' => $accountId,
            'kind' => 'opening_balance',
            'delta' => $debt->outstanding_amount,
            'description' => $description,
            'occurred_at' => $occurredAt,
            'idempotency_key' => Str::uuid()->toString(),
        ]);
    }
}
