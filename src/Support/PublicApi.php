<?php

namespace Aurora\Support;

use Aurora\Enums\Api;
use Aurora\Models\ApiKey;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The website's read-only APIs (Settings › Integrations): pages, menus and the website's
 * settings, over REST or GraphQL, for a frontend built apart from Laravel, an app or another
 * service. Each is off until switched on; then open to anyone, or only with an API key
 * (Aurora\Models\ApiKey, the same keys for both). Only what visitors may see: live pages,
 * never one behind a password or sign-in.
 */
class PublicApi
{
    public const REST_PREFIX = 'api/aurora/v1';

    public const GRAPHQL_PATH = 'api/aurora/graphql';

    public static function enabled(Api $api): bool
    {
        return (bool) ((Setting::get($api->setting()) ?? [])['enabled'] ?? false);
    }

    public static function needsKey(Api $api): bool
    {
        return (bool) ((Setting::get($api->setting()) ?? [])['requires_key'] ?? true);
    }

    /**
     * Whether this request may read the API: switched on, and with one of the API keys when a
     * key is needed (Authorization: Bearer …, or ?key=…). The keys work for both APIs.
     */
    public static function allows(Api $api, Request $request): bool
    {
        if (! static::enabled($api)) {
            return false;
        }

        if (! static::needsKey($api)) {
            return true;
        }

        $given = (string) ($request->bearerToken() ?? $request->query('key', ''));
        $key = $given === '' ? null : ApiKey::query()->where('key_hash', ApiKey::hash($given))->first();

        if ($key === null) {
            return false;
        }

        // When it was last used, to a minute: enough to tell a key in use from a forgotten one.
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return true;
    }

    /**
     * The live page anyone may see at an address on the website ("/" is the homepage), as a
     * frontend routing its own addresses asks for it.
     */
    public static function pageAt(string $path): ?Page
    {
        $path = trim($path, '/');

        $page = $path === ''
            ? Page::query()->public()->homepage()->first()
            : Page::query()->public()->whereSlug(Str::afterLast($path, '/'), ContentLocale::current())->first();

        // The rest of the address must be where the page sits.
        if ($page === null || ($page->path() !== '/'.ltrim(ContentLocale::prefix().'/'.$path, '/') && $page->path() !== '/'.$path)) {
            return null;
        }

        return $page;
    }

    /**
     * The settings anyone may read: the site's own name, tagline, colours and contact address,
     * and the keys the settings tabs name public (SettingsTabs::register(publicKeys:)). Never
     * the CMS's own: keys, sign-in rules, who may see the site.
     *
     * @return array<int, string>
     */
    public static function publicSettings(): array
    {
        return array_values(array_unique([...ApiContent::SETTINGS, ...SettingsTabs::publicKeys()]));
    }
}
