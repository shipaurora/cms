<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\CurrentSite;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('whoami')]
#[Description('Who you are acting as, your role and exactly what you are allowed to do in this CMS. Call this first.')]
#[IsReadOnly]
class WhoAmI extends CmsTool
{
    protected bool $needsSite = false;

    protected function run(Request $request, User $user): Response
    {
        return Response::json([
            'user' => ['name' => $user->name, 'email' => $user->email, 'roles' => $user->getRoleNames()],
            // The site these calls work on (the "site" argument, or the only one), and the others.
            'site' => ($site = CurrentSite::get()) === null ? null : ['handle' => $site->handle, 'name' => Setting::get('site_name', $site->name), 'url' => $site->url('/')],
            'sites' => ($sites = $user->accessibleSites())->count() > 1
                ? $sites->map(fn (Site $site): array => ['handle' => $site->handle, 'name' => $site->setting('site_name', $site->name), 'url' => $site->url('/')])->values()->all()
                : null,
            'you_can' => [
                'list_and_read_pages' => $user->can('viewAny', Page::class),
                'create_pages' => $user->can('create', Page::class),
                'edit_your_own_pages' => $user->can('update Page'),
                'edit_everyones_pages' => $user->can('update-any Page'),
                'publish_pages' => $user->can('publish', Page::class),
                'delete_pages' => $user->can('delete Page'),
                'manage_menus' => $user->can('update Menu'),
                'use_custom_code_blocks' => $user->can('use-code-blocks'),
            ],
            'notes' => array_values(array_filter([
                ($user->accessibleSites()->count() > 1) ? 'This CMS runs several sites (listed under "sites"). Pass "site" (its handle) to every tool, and check with the user which site they mean.' : null,
                'Every change you make is saved as a revision attributed to '.$user->name.' and can be restored in the admin panel.',
                $user->can('publish', Page::class)
                    ? 'You may publish. Prefer leaving new pages as drafts and sharing the preview link unless asked to publish.'
                    : 'You cannot publish. Set status to "review" when a page is ready, and an editor will publish it.',
            ])),
        ]);
    }
}
