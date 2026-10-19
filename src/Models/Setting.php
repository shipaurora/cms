<?php

namespace Aurora\Models;

use Aurora\Support\CurrentSite;
use Aurora\Support\SettingsTabs;
use Aurora\Support\WebsiteAccess;
use Aurora\Support\WorkingCopies;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    use HasUuids;

    protected const CACHE_KEY = 'site-settings';

    /**
     * Settings each site has for itself (kept on the site): its name and look, and whatever
     * its own settings tabs add (Aurora\Support\SettingsTabs). Everything else (the AI
     * assistant …) is the CMS's.
     *
     * @var array<int, string>
     */
    public const SITE_KEYS = ['site_name', 'tagline', 'brand_color', 'accent_color', 'contact_email', WebsiteAccess::SETTING, WorkingCopies::SETTING];

    /**
     * @return array<int, string>
     */
    public static function siteKeys(): array
    {
        return [...self::SITE_KEYS, ...SettingsTabs::siteKeys()];
    }

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function values(): array
    {
        return Cache::memo()->rememberForever(static::CACHE_KEY, fn (): array => static::query()->pluck('value', 'key')->all());
    }

    /**
     * Every setting as the current site sees it: the CMS's, with the site's own on top.
     *
     * @return array<string, mixed>
     */
    public static function forCurrentSite(): array
    {
        $values = array_diff_key(static::values(), array_flip(static::siteKeys()));
        $site = CurrentSite::get();

        if ($site === null) {
            return static::values();
        }

        return [...$values, 'site_name' => $site->name, ...array_intersect_key($site->settings ?? [], array_flip(static::siteKeys()))];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (static::isSiteKey($key) && ($site = CurrentSite::get()) !== null) {
            return $site->setting($key) ?? ($key === 'site_name' ? $site->name : null) ?? $default;
        }

        return data_get(static::values(), $key) ?? $default;
    }

    /**
     * Site keys go to the current site, the others are the CMS's.
     *
     * @param  array<string, mixed>  $values
     */
    public static function put(array $values): void
    {
        $site = CurrentSite::get();
        $siteValues = $site === null ? [] : array_filter($values, fn (string $key): bool => static::isSiteKey($key), ARRAY_FILTER_USE_KEY);

        if ($siteValues !== []) {
            $site->update(['settings' => [...$site->settings ?? [], ...$siteValues]]);
        }

        foreach (array_diff_key($values, $siteValues) as $key => $value) {
            static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::memo()->forget(static::CACHE_KEY);
    }

    protected static function isSiteKey(string $key): bool
    {
        return in_array(explode('.', $key)[0], static::siteKeys(), true);
    }
}
