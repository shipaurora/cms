<?php

namespace Aurora\Policies;

use Aurora\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared sections show on many pages, so changing one is changing all of them: for people
 * who may edit every page.
 */
class SharedSectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-any Page');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can('view-any Page');
    }

    public function create(User $user): bool
    {
        return $user->can('update-any Page');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can('update-any Page');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can('update-any Page');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('update-any Page');
    }
}
