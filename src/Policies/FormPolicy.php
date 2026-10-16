<?php

namespace Aurora\Policies;

use Aurora\Models\Form;
use Aurora\Models\User;

class FormPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-any Form');
    }

    public function view(User $user, Form $form): bool
    {
        return $user->can('view-any Form');
    }

    public function create(User $user): bool
    {
        return $user->can('update Form');
    }

    public function update(User $user, Form $form): bool
    {
        return $user->can('view-any Form');
    }

    public function delete(User $user, Form $form): bool
    {
        return $user->can('update Form');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('update Form');
    }

    public function restore(User $user, Form $form): bool
    {
        return $user->can('update Form');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('update Form');
    }

    public function forceDelete(User $user, Form $form): bool
    {
        return $user->can('update Form');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('update Form');
    }
}
