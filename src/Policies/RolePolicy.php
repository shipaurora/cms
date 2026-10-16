<?php

namespace Aurora\Policies;

use Aurora\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Roles and permissions shape who can do what, so changing them is reserved.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-any Role');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->isSuperAdmin() || $user->can('view-any Role');
    }

    public function create(User $user): bool
    {
        return $user->can('update Role');
    }

    /**
     * Not the Super Admin role, and not a role you hold yourself (you could give it, and so
     * yourself, any permission), unless you are a Super Admin.
     */
    public function update(User $user, Model $model): bool
    {
        return ($user->isSuperAdmin() || $user->can('update Role')) && ($model->name ?? null) !== User::SUPER_ADMIN
            && ($user->isSuperAdmin() || ! $user->hasRole($model->name ?? ''));
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('update Role');
    }
}
