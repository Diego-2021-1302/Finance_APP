<?php

namespace App\Policies;

use App\Models\Income;
use App\Models\User;

class IncomePolicy
{
    public function view(User $user, Income $income): bool { return (int) $income->user_id === (int) $user->id; }
    public function update(User $user, Income $income): bool { return (int) $income->user_id === (int) $user->id; }
}
