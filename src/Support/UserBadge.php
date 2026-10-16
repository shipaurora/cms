<?php

namespace Aurora\Support;

use Aurora\Models\User;
use Illuminate\Support\HtmlString;

/**
 * A person's name with their avatar in front, wherever the admin shows who did something
 * (revisions, the AI activity log, invitations, "last edited by"). Styled by the admin
 * theme (.cms-user-badge).
 */
class UserBadge
{
    public static function html(?User $user, ?string $prefix = null, ?string $suffix = null): ?HtmlString
    {
        if ($user === null) {
            return null;
        }

        return new HtmlString(sprintf(
            '<span class="cms-user-badge">%s<img src="%s" alt="" loading="lazy" class="cms-user-badge-avatar"><span>%s</span>%s</span>',
            $prefix === null ? '' : '<span class="cms-user-badge-text">'.e($prefix).'</span>',
            e(filament()->getUserAvatarUrl($user)),
            e($user->name),
            $suffix === null ? '' : '<span class="cms-user-badge-text">'.e($suffix).'</span>',
        ));
    }
}
