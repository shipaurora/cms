<?php

namespace Aurora\Policies;

use Aurora\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The activity log is read, never changed.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-any Activity');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can('view-any Activity');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
