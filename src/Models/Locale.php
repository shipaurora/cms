<?php

namespace Aurora\Models;

use Aurora\Database\Factories\LocaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * A language the CMS is available in. The default one is the website's language and the
 * fallback for every label; people can pick any active one for the admin panel.
 */
#[Fillable(['code', 'name', 'prefix', 'is_default', 'is_active', 'sort'])]
class Locale extends Model
{
    /** @use HasFactory<LocaleFactory> */
    use HasFactory, HasUuids;

    protected const CACHE_KEY = 'cms.locales';

    protected const PREFIXES_CACHE_KEY = 'cms.locale-prefixes';

    /**
     * Languages offered when adding one: code => name in that language.
     *
     * @var array<string, string>
     */
    public const KNOWN = [
        'en' => 'English', 'nb' => 'Norsk bokmål', 'nn' => 'Norsk nynorsk', 'sv' => 'Svenska', 'da' => 'Dansk',
        'fi' => 'Suomi', 'de' => 'Deutsch', 'nl' => 'Nederlands', 'fr' => 'Français', 'es' => 'Español',
        'it' => 'Italiano', 'pt' => 'Português', 'pl' => 'Polski', 'cs' => 'Čeština', 'et' => 'Eesti',
        'lv' => 'Latviešu', 'lt' => 'Lietuvių', 'uk' => 'Українська', 'ja' => '日本語', 'zh_CN' => '简体中文',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // There is always exactly one default, and it is active.
        static::saving(function (Locale $locale): void {
            if ($locale->is_default) {
                $locale->is_active = true;
            }

            // Unticking the default would leave the website without one: pick another instead.
            if (! $locale->is_default && $locale->getOriginal('is_default')) {
                throw ValidationException::withMessages(['is_default' => __('There is always a default site language. Make another language the default instead.')]);
            }
        });

        static::deleting(function (Locale $locale): void {
            if ($locale->is_default) {
                throw ValidationException::withMessages(['is_default' => __('The default site language can’t be removed. Make another language the default first.')]);
            }
        });

        static::saved(function (Locale $locale): void {
            if ($locale->is_default) {
                static::query()->whereKeyNot($locale->getKey())->where('is_default', true)->update(['is_default' => false]);
            }

            Cache::memo()->forget(static::CACHE_KEY);
            Cache::memo()->forget(static::PREFIXES_CACHE_KEY);
        });

        static::deleted(function (): void {
            Cache::memo()->forget(static::CACHE_KEY);
            Cache::memo()->forget(static::PREFIXES_CACHE_KEY);
        });
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Active locales, default first: code => name.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        try {
            return Cache::memo()->rememberForever(static::CACHE_KEY, fn (): array => static::query()
                ->active()
                ->orderByDesc('is_default')
                ->orderBy('sort')
                ->orderBy('name')
                ->pluck('name', 'code')
                ->all());
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * Each language's address prefix, without slashes: its own, else its code.
     *
     * @return array<string, string> code => prefix
     */
    public static function prefixes(): array
    {
        try {
            return Cache::memo()->rememberForever(static::PREFIXES_CACHE_KEY, fn (): array => static::query()
                ->get(['code', 'prefix'])
                ->mapWithKeys(fn (Locale $locale): array => [$locale->code => $locale->prefix ?: $locale->code])
                ->all());
        } catch (QueryException) {
            return [];
        }
    }

    public static function prefixFor(string $code): string
    {
        return static::prefixes()[$code] ?? $code;
    }

    /**
     * Why a prefix can't be used for a language, or null when it can: the website's addresses
     * in that language start with it, so it can't be one the app, another language or a page
     * already has. A language may use its own code ("en" for English).
     *
     * $againstExisting false leaves out the languages and pages in the database, for an
     * install that is about to start over without them.
     */
    public static function prefixProblem(string $prefix, ?string $code = null, bool $againstExisting = true): ?string
    {
        return match (true) {
            ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $prefix) => __('Use lowercase letters, digits and dashes.'),
            mb_strlen($prefix) > 32 => __('Use at most 32 characters.'),
            in_array($prefix, Page::RESERVED_SLUGS, true) => __('This address is reserved by the application.'),
            ! $againstExisting => null,
            in_array($prefix, array_values(array_diff_key(static::prefixes(), [(string) $code => true])), true) => __('Another language already uses this prefix.'),
            ! Page::slugIsAvailable($prefix, static::defaultCode()) => __('A page already has this address.'),
            default => null,
        };
    }

    /**
     * The website's language.
     */
    public static function defaultCode(): string
    {
        return array_key_first(static::options()) ?? config('app.fallback_locale', 'en');
    }

    public static function isActiveCode(?string $code): bool
    {
        return filled($code) && array_key_exists($code, static::options());
    }
}
