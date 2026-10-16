<?php

namespace Aurora\Models\Pivots;

use Aurora\Models\Role;
use Aurora\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Validation\ValidationException;

/**
 * A row of model_has_roles: one role given to one user.
 *
 * Both sides of the relationship (a user's roles, a role's users) use this pivot, so
 * every way of taking a role away, from the user form to the role screen's Detach and
 * syncRoles() in code, passes through deleting() below.
 */
class RoleAssignment extends MorphPivot
{
    protected static function booted(): void
    {
        static::deleting(function (self $assignment): void {
            $isSelf = auth()->check()
                && (string) $assignment->getAttribute(config('permission.column_names.model_morph_key') ?? 'model_id') === (string) auth()->id();

            if ($isSelf && Role::query()->whereKey($assignment->getAttribute(config('permission.column_names.role_pivot_key') ?? 'role_id'))->value('name') === User::SUPER_ADMIN) {
                throw ValidationException::withMessages([
                    'roles' => 'You cannot remove the Super Admin role from yourself. Ask another Super Admin to do it.',
                ]);
            }
        });
    }
}
