<?php

namespace App\Actions;

use App\Models\User;
use App\Services\TransactionService;

class RecordExpense
{
    public function __construct(private readonly TransactionService $service) {}

    public function execute(User $user, array $input): array
    {
        return $this->service->recordExpense($user, $input);
    }
}
