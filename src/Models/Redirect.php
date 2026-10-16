<?php

namespace Aurora\Models;

use Aurora\Models\Concerns\BelongsToSite;
use Aurora\Support\AssetLibrary;
use Aurora\Support\ContentLocale;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['from_path', 'page_id', 'asset_id', 'to_url', 'status_code'])]
/**
 * An old address that sends visitors on: to a page (following it when its address changes),
 * to a file in the media library (following it when the file is replaced) or to a URL.
 */
class Redirect extends Model
{
    use BelongsToSite, HasUuids;

    protected static function booted(): void
    {
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
}
