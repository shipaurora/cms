<?php

namespace Aurora\Models;

use Aurora\Models\Concerns\BelongsToSite;
use Aurora\Support\AssetLibrary;
use Aurora\Support\ContentLocale;
use Aurora\Support\CurrentSite;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['from_path', 'page_id', 'asset_id', 'to_url', 'status_code'])]
/**
 * An old address that sends visitors on: to a page (following it when its address changes),
 * to a file in the media library (following it when the file is replaced) or to a URL.
 * Never round in a circle: a redirect that would lead back to an address on its way (loop())
 * isn't saved, and one that comes to since (a page it leads to moved) isn't followed.
 */
class Redirect extends Model
{
    use BelongsToSite, HasUuids;

    protected static function booted(): void
    {
        static::saving(function (Redirect $redirect): void {
            if (($loop = $redirect->loop()) !== null) {
                throw ValidationException::withMessages(['from_path' => static::loopMessage($loop)]);
            }
        });

        // A file a redirect points at counts as used, so the library warns before deleting it.
        static::saved(fn (Redirect $redirect) => AssetLibrary::syncUsages($redirect, ['redirect' => $redirect->asset_id]));
        static::deleted(fn (Redirect $redirect) => AssetUsage::query()->whereMorphedTo('usable', $redirect)->delete());
    }

    protected function casts(): array
    {
        return [
            'last_hit_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function destination(): ?string
    {
        return $this->page?->url($this->locale()) ?? $this->asset?->url(null) ?? $this->to_url;
    }

    /**
     * The language of the old address ("/nb/om-oss" is Norwegian), so a page redirect goes to
     * the page in that language.
     */
    public function locale(): string
    {
        foreach (array_keys(ContentLocale::all()) as $locale) {
            $prefix = ContentLocale::prefix($locale);

            if ($prefix !== '' && ($this->from_path === $prefix || str_starts_with((string) $this->from_path, $prefix.'/'))) {
                return $locale;
            }
        }

        return ContentLocale::default();
    }

    public static function normalisePath(string $path): string
    {
        return '/'.trim(parse_url($path, PHP_URL_PATH) ?? $path, '/');
    }

    /**
     * The addresses visitors would go round if they followed this redirect, the way the website
     * does: on from each address through the redirect set up for it, until a live page answers
     * there or the way leaves the site. A trail that comes back to an address on it is a loop
     * ("/a", "/b", "/a"); null when it ends somewhere.
     *
     * @return array<int, string>|null
     */
    public function loop(): ?array
    {
        $trail = [static::normalisePath((string) $this->from_path)];

        // A live page answers at the old address itself: the redirect is never asked while it does.
        if (static::pageAnswersAt($trail[0])) {
            return null;
        }

        $path = $this->pathOnThisSite($this->destination());

        while ($path !== null) {
            if (in_array($path, $trail, true)) {
                return [...$trail, $path];
            }

            if (static::pageAnswersAt($path)) {
                return null;
            }

            $trail[] = $path;
            $next = static::query()->where('from_path', $path)->when($this->getKey(), fn ($query) => $query->whereKeyNot($this->getKey()))->first();
            $path = $next === null ? null : $this->pathOnThisSite($next->destination());
        }

        return null;
    }

    /**
     * "Visitors would go round in a circle: /a → /b → /a."
     *
     * @param  array<int, string>  $loop
     */
    public static function loopMessage(array $loop): string
    {
        return __('Visitors would go round in a circle: :addresses. Send this address somewhere that ends.', ['addresses' => implode(' → ', $loop)]);
    }

    /**
     * A destination's address on this redirect's site, or null when it leaves the site.
     */
    protected function pathOnThisSite(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $own = parse_url(($this->site ?? CurrentSite::get())?->url('/') ?? (string) config('app.url'), PHP_URL_HOST);

        return $host === null || $host === $own ? static::normalisePath($url) : null;
    }

    /**
     * Whether a live page answers at exactly this address: there the website shows the page
     * and a redirect from it is never asked.
     */
    protected static function pageAnswersAt(string $path): bool
    {
        $locale = (new static(['from_path' => $path]))->locale();
        $prefix = ContentLocale::prefix($locale);
        $rest = $prefix !== '' && str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;

        if (trim($rest, '/') === '') {
            return (bool) Page::query()->homepage()->first()?->isLive();
        }

        $page = Page::findByPath($rest, $locale);

        return $page !== null && $page->isLive() && $page->isAvailableIn($locale) && $page->path($locale) === $path;
    }
}
