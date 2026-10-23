<?php

namespace Aurora\Models;

use Aurora\Support\TranslationLoader;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\TranslationLoader\LanguageLine as SpatieLanguageLine;

/**
 * A translatable label (spatie/laravel-translation-loader): a group and key, with its text
 * per locale. Group "*" holds sentence-style keys, whose key is also the default text.
 */
class LanguageLine extends SpatieLanguageLine
{
    use HasUuids;

    protected $casts = [
        'text' => 'array',
        'imported' => 'array',
        'last_seen_at' => 'datetime',
    ];

    /**
     * This locale's text as edited in the admin, laid over the lang files (TranslationLoader).
     * Text still as it was imported follows the files instead, where the app's own beat
     * Aurora's and the packages': a site's "Events": "Events" in its lang/nb.json is its
     * label straight away, without importing, whatever Aurora's says. Only this locale's
     * own (the package falls back to the fallback locale here, which would put an English
     * override over a package's own Norwegian text).
     */
    public function getTranslation(string $locale): ?string
    {
        $text = $this->text[$locale] ?? null;

        return $text !== null && $text !== ($this->imported[$locale] ?? null) ? $text : null;
    }

    /**
     * Versioned: caches from when every imported text was laid over the files aren't read.
     */
    public static function getCacheKey(string $group, string $locale): string
    {
        return 'aurora.translation-loader.v2.'.$group.'.'.$locale;
    }

    /**
     * The full key, as passed to __(): "Thank you!", "validation.required",
     * "filament-panels::layout.actions.logout.label".
     */
    public function fullKey(): string
    {
        return match (true) {
            $this->group === '*' => $this->key,
            str_contains($this->group, '::') => $this->group.'.'.$this->key,
            default => $this->group.'.'.$this->key,
        };
    }

    /**
     * Where the label comes from: "App", "Laravel" or the package's namespace.
     */
    public function source(): string
    {
        return match (true) {
            str_contains($this->group, '::') => str($this->group)->before('::')->toString(),
            $this->group === '*' || ! in_array($this->group, ['auth', 'pagination', 'passwords', 'validation'], true) => 'App',
            default => 'Laravel',
        };
    }

    /**
     * The label's text in a locale from the lang files, before any edits.
     */
    public function fileText(string $locale): ?string
    {
        $loader = app('translator')->getLoader();

        return $loader instanceof TranslationLoader ? $loader->fileText($locale, $this->group, $this->key) : null;
    }

    /**
     * What the label says in a locale right now: the edited text, else the lang files'.
     */
    public function textIn(string $locale): ?string
    {
        return $this->text[$locale] ?? $this->fileText($locale);
    }

    /**
     * Locales whose text was changed in the admin (differs from the lang files' import).
     *
     * @return array<int, string>
     */
    public function editedLocales(): array
    {
        return array_keys(array_filter(
            $this->text ?? [],
            fn (?string $value, string $locale): bool => filled($value) && $value !== ($this->imported[$locale] ?? null),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    public function isTranslatedInto(string $locale): bool
    {
        return filled($this->text[$locale] ?? null);
    }

    /**
     * Set or clear one locale's text (empty clears it, so the default shows instead).
     */
    public function putTranslation(string $locale, ?string $value): static
    {
        $text = $this->text ?? [];

        if (blank($value)) {
            unset($text[$locale]);
        } else {
            $text[$locale] = $value;
        }

        // Clear the cache for a removed locale too.
        $this->flushGroupCache();
        $this->text = $text;

        return $this;
    }
}
