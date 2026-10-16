<?php

namespace Aurora\Policies;

use Aurora\Models\User;
use Illuminate\Database\Eloquent\Model;

class RedirectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-any Redirect');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can('view-any Redirect');
    }

    public function create(User $user): bool
    {
        return $user->can('update Redirect');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can('update Redirect');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can('update Redirect');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('update Redirect');
    }
}
