<?php

namespace Aurora\Policies;

use Aurora\Models\Page;
use Aurora\Models\User;

/**
 * Enforced everywhere a page can change: the admin panel, the visual editor and MCP.
 *
 * Authors may edit pages they wrote; editors may edit anyone's. Publishing is a separate
 * permission, so an author can prepare a page but not put it live.
 */
class PagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-any Page');
    }

    public function view(User $user, Page $page): bool
    {
        return $user->can('view-any Page');
    }

    public function create(User $user): bool
    {
        return $user->can('create Page');
    }

    public function update(User $user, Page $page): bool
    {
        if ($user->can('update-any Page')) {
            return true;
        }

        return $user->can('update Page') && $page->author_id === $user->getKey();
    }

    public function publish(User $user, ?Page $page = null): bool
    {
        if (! $user->can('publish Page')) {
            return false;
        }

        return $page === null || ! $page->exists || $this->update($user, $page);
    }

    public function delete(User $user, Page $page): bool
    {
        return $user->can('delete Page') && $this->update($user, $page);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete Page');
    }

    public function restore(User $user, Page $page): bool
    {
        return $user->can('restore Page');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore Page');
    }

    public function forceDelete(User $user, Page $page): bool
    {
        return $user->can('force-delete Page');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force-delete Page');
    }

    public function replicate(User $user, Page $page): bool
    {
        return $user->can('create Page');
    }
}
