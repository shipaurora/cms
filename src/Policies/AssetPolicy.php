<?php

namespace Aurora\Policies;

use Aurora\Models\Asset;
use Aurora\Models\User;
use Aurora\Support\AssetAccess;

/**
 * Using the library (browse, pick, upload) and managing it (edit, replace, delete) are
 * separate, so authors can add images without being able to change everyone else's. A
 * file's own access (AssetAccess) narrows both: reading it, and changing it.
 */
class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-any Asset');
    }

    public function view(User $user, Asset $asset): bool
    {
        return $user->can('view-any Asset') && AssetAccess::canRead($user, $asset);
    }

    public function create(User $user): bool
    {
        return $user->can('view-any Asset');
    }

    public function update(User $user, Asset $asset): bool
    {
        return ($user->can('update Asset') || ($user->can('view-any Asset') && $asset->uploaded_by_id === $user->getKey()))
            && AssetAccess::canWrite($user, $asset);
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $user->can('update Asset') && AssetAccess::canWrite($user, $asset);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('update Asset');
    }

    public function restore(User $user, Asset $asset): bool
    {
        return $user->can('update Asset') && AssetAccess::canWrite($user, $asset);
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('update Asset');
    }

    public function forceDelete(User $user, Asset $asset): bool
    {
        return $user->can('update Asset') && AssetAccess::canWrite($user, $asset);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('update Asset');
    }
}
