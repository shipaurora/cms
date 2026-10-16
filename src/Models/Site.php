<?php

namespace Aurora\Models;

use Aurora\Support\CurrentSite;
use Filament\Facades\Filament;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasCurrentTenantLabel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A website this CMS runs. There is always one, the default (without a domain: it answers on
 * any address); more can be added, each with its own pages, menus, redirects and
 * settings and forms, its own domain, languages and people. Media is shared by all.
 */
#[Fillable(['name', 'avatar', 'handle', 'domain', 'is_default', 'default_locale', 'locales', 'settings', 'sort'])]
class Site extends Model implements HasAvatar, HasCurrentTenantLabel
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'locales' => 'array',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // There is always exactly one default site.
        static::saved(function (Site $site): void {
            if ($site->is_default) {
                static::query()->whereKeyNot($site->getKey())->where('is_default', true)->update(['is_default' => false]);
            }

            CurrentSite::forget();
        });

        static::saving(function (Site $site): void {
            if (! $site->is_default && $site->getOriginal('is_default')) {
                throw ValidationException::withMessages(['is_default' => __('There is always a default site. Make another site the default instead.')]);
            }
        });

        static::deleting(function (Site $site): void {
            if ($site->is_default) {
                throw ValidationException::withMessages(['site' => __('The default site can’t be deleted.')]);
            }
        });
    }

    /**
     * In the site switcher: the site's own avatar, else its initial on its brand colours,
     * drawn here rather than fetched from an avatar service.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        if (filled($this->avatar)) {
            return Storage::disk('public')->url($this->avatar);
        }

        $from = $this->setting('brand_color', '#7c3aed');
        $to = $this->setting('accent_color', '#22d3ee');
        $initial = e(mb_strtoupper(mb_substr($this->name, 0, 1)));

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{$from}"/><stop offset="1" stop-color="{$to}"/></linearGradient></defs><rect width="64" height="64" rx="16" fill="url(#g)"/><text x="32" y="42" font-family="system-ui,sans-serif" font-size="30" font-weight="700" text-anchor="middle" fill="#fff">{$initial}</text></svg>
            SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public function getCurrentTenantLabel(): string
    {
        return __('Site');
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    public function redirects(): HasMany
    {
        return $this->hasMany(Redirect::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * The site's address for a path: on its own domain when it has one.
     */
    public function url(string $path = '/'): string
    {
        $path = ltrim($path, '/');

        return static::baseUrl($this->domain).($path === '' ? '' : '/'.$path);
    }

    /**
     * Where a site with this domain lives: https://{domain}, or APP_URL for a site without
     * one (never the address the admin happens to be opened on).
     */
    public static function baseUrl(?string $domain): string
    {
        $app = rtrim((string) config('app.url'), '/');

        return filled($domain)
            ? (parse_url($app, PHP_URL_SCHEME) ?: 'https').'://'.trim((string) $domain, '/')
            : $app;
    }

    /**
     * The admin for a site with this domain: /admin on it (the panel answers on any domain).
     */
    public static function adminUrl(?string $domain): string
    {
        return static::baseUrl($domain).'/'.trim(Filament::getPanel('admin')->getPath(), '/');
    }

    /**
     * A site setting (site_name, brand_color, socials …), see Setting::siteKeys().
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key) ?? $default;
    }
}
