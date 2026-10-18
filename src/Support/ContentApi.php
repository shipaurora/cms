<?php

namespace Aurora\Support;

use Aurora\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The read-only content API (Settings › Integrations › Content API): pages, menus and the
 * website's settings as JSON, for a frontend built apart from Laravel, an app or another
 * service. Off until switched on; then open to anyone, or only with the key (shown once, kept
 * hashed). Only what visitors may see: live pages, never one behind a password or sign-in.
 */
class ContentApi
{
    public const SETTING = 'content_api';

    /**
     * The key's hash, kept apart from the settings form's values (which saving replaces).
     */
    public const KEY_SETTING = 'content_api_key';

    public const PREFIX = 'api/aurora/v1';

    public static function enabled(): bool
    {
        return (bool) ((Setting::get(self::SETTING) ?? [])['enabled'] ?? false);
    }

    public static function needsKey(): bool
    {
        return (bool) ((Setting::get(self::SETTING) ?? [])['requires_key'] ?? true);
    }

    public static function hasKey(): bool
    {
        return filled(Setting::get(self::KEY_SETTING));
    }

    /**
     * A new key, replacing the old one: shown to the person once, then only its hash is kept.
     */
    public static function newKey(): string
    {
        $key = 'aurora_'.Str::random(40);
        Setting::put([self::KEY_SETTING => hash('sha256', $key)]);

        return $key;
    }

    /**
     * Whether this request may read the API: switched on, and with the key when one is needed
     * (Authorization: Bearer …, or ?key=…).
     */
    public static function allows(Request $request): bool
    {
        if (! static::enabled()) {
            return false;
        }

        if (! static::needsKey()) {
            return true;
        }

        $given = (string) ($request->bearerToken() ?? $request->query('key', ''));
        $hash = Setting::get(self::KEY_SETTING);

        return $given !== '' && is_string($hash) && hash_equals($hash, hash('sha256', $given));
    }

    /**
     * The settings the website reads that may be shown to anyone: the site's own (name,
     * tagline, colours …) and those of the website's settings tabs, never the CMS's private ones.
     *
     * @return array<int, string>
     */
    public static function publicSettings(): array
    {
        return array_values(array_diff(Setting::siteKeys(), [WebsiteAccess::SETTING]));
    }
}
