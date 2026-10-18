<?php

namespace Aurora\Support;

use Aurora\Models\Setting;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A website open only to some addresses while it isn't ready (a staging site, an intranet):
 * Settings › Security › Website access, for each site. People signed in to the CMS always
 * get in, so it can be worked on.
 */
class WebsiteAccess
{
    public const SETTING = 'website_access';

    public static function restricted(): bool
    {
        return (bool) ((Setting::get(self::SETTING) ?? [])['restricted'] ?? false);
    }

    /**
     * The addresses and ranges let in (192.168.0.0/24), one per line.
     *
     * @return array<int, string>
     */
    public static function addresses(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ((Setting::get(self::SETTING) ?? [])['addresses'] ?? '')))));
    }

    public static function allows(Request $request): bool
    {
        return ! static::restricted()
            || auth()->check()
            || IpUtils::checkIp((string) $request->ip(), static::addresses());
    }

    /**
     * An address or range that can be checked.
     */
    public static function isValidAddress(string $address): bool
    {
        [$ip, $mask] = array_pad(explode('/', $address, 2), 2, null);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false
            && ($mask === null || (ctype_digit($mask) && (int) $mask <= (str_contains($ip, ':') ? 128 : 32)));
    }
}
