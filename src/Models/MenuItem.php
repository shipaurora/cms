<?php

namespace Aurora\Models;

use Aurora\Support\ContentLocale;
use Aurora\Support\SafeUrl;
use Datlechin\FilamentMenuBuilder\Models\MenuItem as BaseMenuItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Translatable\HasTranslations;

/**
 * A menu item, its title per language. A page's item left without a title of its own in a
 * language shows the page's title in that language: "Om oss" for "About us".
 */
class MenuItem extends BaseMenuItem
{
    use HasTranslations {
        HasTranslations::getTranslation as translatedValue;
    }
    use HasUuids;

    /**
     * @var array<int, string>
     */
    public array $translatable = ['title'];

    /**
     * Items belong to the site their menu belongs to: through the menu's own site scope, so an
     * item id from another site finds nothing (in the editor, the package's actions and MCP).
     */
    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('site', fn (Builder $query) => $query->whereHas('menu'));
    }

    public function getLocale(): string
    {
        return ContentLocale::current();
    }

    public function getTranslation(string $key, string $locale, bool $useFallbackLocale = true): mixed
    {
        if ($key === 'title' && $this->usesPageTitle($locale)) {
            return $this->linkable->getTranslation('title', $locale, $useFallbackLocale);
        }

        return $this->translatedValue($key, $locale, $useFallbackLocale);
    }

    /**
     * A page's item without its own title in this language shows the page's title in it,
     * language by language: an own Norwegian title doesn't stand in for a missing English one.
     */
    public function usesPageTitle(string $locale): bool
    {
        return $this->linkable instanceof Page && blank($this->getTranslations('title')[$locale] ?? null);
    }

    /**
     * The package's link (a page's address, or the one typed in), never an unsafe one
     * (javascript: and the like): those were refused when saved, and are dropped here too.
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn (?string $value): ?string => SafeUrl::href(parent::url()->get->__invoke($value)));
    }
}
