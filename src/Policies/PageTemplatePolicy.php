<?php

namespace Aurora\Policies;

use Aurora\Models\PageTemplate;
use Aurora\Models\User;

/**
 * Page templates are managed by those with "Manage page templates", whoever made them. They're
 * never published. Starting a page from one only needs permission to create pages.
 */
class PageTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage-templates Page');
    }

    public function view(User $user, PageTemplate $template): bool
    {
        return $user->can('manage-templates Page');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-templates Page');
    }

    public function update(User $user, PageTemplate $template): bool
    {
        return $user->can('manage-templates Page');
    }

    public function delete(User $user, PageTemplate $template): bool
    {
        return $user->can('manage-templates Page');
    }

    public function forceDelete(User $user, PageTemplate $template): bool
    {
        return $user->can('manage-templates Page');
    }

    public function publish(User $user, ?PageTemplate $template = null): bool
    {
        return false;
    }
}
