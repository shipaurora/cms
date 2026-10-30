<?php

namespace Workbench\App\Policies;

use Aurora\Models\User;
use Workbench\App\Models\Gadget;

/**
 * Editors see, create and change gadgets; nobody but a Super Admin deletes one that's live.
 */
class GadgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Editor');
    }

    public function view(User $user, Gadget $gadget): bool
    {
        return $user->hasRole('Editor');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Editor');
    }

    public function update(User $user, Gadget $gadget): bool
    {
        return $user->hasRole('Editor');
    }

    public function delete(User $user, Gadget $gadget): bool
    {
        return $user->hasRole('Editor') && $gadget->status !== 'live';
    }
}
