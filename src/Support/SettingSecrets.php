<?php

namespace Aurora\Support;

use Aurora\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The secrets a website keeps in Settings (an API key, a token), entered in a SecretInput on a
 * tab that lists them in SettingsTabs::register(secretKeys:). Stored encrypted with the app's
 * key, per site when the tab lists the key in siteKeys too, and never sent to the browser.
 *
 *     $key = SettingSecrets::get('mailchimp_api_key');
 */
class SettingSecrets
{
    public static function get(string $key): ?string
    {
        $encrypted = Setting::get($key);

        if (blank($encrypted) || ! is_string($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            // Encrypted with another APP_KEY: treat it as missing so it can be entered again.
            return null;
        }
    }

    public static function has(string $key): bool
    {
        return static::get($key) !== null;
    }

    /**
     * The last four characters, to recognise which secret is saved without showing it.
     */
    public static function masked(string $key): ?string
    {
        $secret = static::get($key);

        return $secret === null ? null : '••••'.substr($secret, -4);
    }

    /**
     * Save a secret, or remove it (null or empty).
     */
    public static function put(string $key, ?string $secret): void
    {
        Setting::put([$key => blank($secret) ? null : Crypt::encryptString(trim($secret))]);
    }
}
