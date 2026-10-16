<?php

namespace Aurora\Policies;

use Aurora\Models\User;
use Illuminate\Database\Eloquent\Model;

class MenuPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-any Menu');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can('view-any Menu');
    }

    public function create(User $user): bool
    {
        return $user->can('update Menu');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can('update Menu');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can('update Menu');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('update Menu');
    }
}
