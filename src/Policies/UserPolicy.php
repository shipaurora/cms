<?php

namespace Aurora\Policies;

use Aurora\Models\User;

/**
 * Called even for Super Admins (see AppServiceProvider): nobody can delete themselves,
 * and only a Super Admin can change another Super Admin.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->can('view-any User');
    }

    public function view(User $user, User $model): bool
    {
        return $user->is($model) || $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->can('create User');
    }

    public function update(User $user, User $model): bool
    {
        // Everyone edits their own profile; roles and status stay with people who manage users.
        if ($user->isSuperAdmin() || $user->is($model)) {
            return true;
        }

        return $user->can('update User') && ! $model->isSuperAdmin();
    }

    public function delete(User $user, User $model): bool
    {
        if ($user->is($model)) {
            return false;
        }

        return $user->isSuperAdmin() || ($user->can('delete User') && ! $model->isSuperAdmin());
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->can('delete User');
    }
}
