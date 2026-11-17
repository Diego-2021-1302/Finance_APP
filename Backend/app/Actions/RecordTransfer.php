<?php

namespace App\Actions;

use App\Models\User;
use App\Services\TransactionService;

class RecordTransfer
{
    public function __construct(private readonly TransactionService $service) {}

    public function execute(User $user, array $input): array
    {
        return $this->service->recordTransfer($user, $input);
    }
}
