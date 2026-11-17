<?php

namespace App\Policies;

use App\Models\Commitment;
use App\Models\User;

class CommitmentPolicy
{
    public function view(User $user, Commitment $commitment): bool { return (int) $commitment->user_id === (int) $user->id; }
    public function update(User $user, Commitment $commitment): bool { return (int) $commitment->user_id === (int) $user->id; }
}
