<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\ContentLocale;
use Aurora\Support\CurrentSite;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_sites')]
#[Description('The websites this CMS runs that you may work on. Pass a site\'s handle as "site" to the other tools.')]
#[IsReadOnly]
class ListSites extends CmsTool
{
    protected bool $needsSite = false;

    /**
     * Only a question to ask with more than one site.
     */
    public function shouldRegister(Request $request): bool
    {
        return ($this->actingUser($request)?->accessibleSites()->count() ?? 0) > 1;
    }

    protected function run(Request $request, User $user): Response
    {
        return Response::json([
            'sites' => $user->accessibleSites()->map(fn (Site $site): array => CurrentSite::using($site, fn (): array => [
                'id' => $site->getKey(),
                'handle' => $site->handle,
                'name' => $site->setting('site_name', $site->name),
                'domain' => $site->domain,
                'url' => $site->url('/'),
                'is_default' => $site->is_default,
                'default_language' => ContentLocale::default(),
                'languages' => array_keys(ContentLocale::all()),
            ]))->values()->all(),
        ]);
    }
}
