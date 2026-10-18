<?php

namespace Aurora\Models;

use Aurora\Enums\AssetType;
use Aurora\Support\ContentLocale;
use Aurora\Support\Renditions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Spatie\Translatable\HasTranslations;

/**
 * One file in the media library: the file itself (a Spatie media item, stored on the
 * MEDIA_DISK, e.g. S3) plus what editors know about it, written once and reused
 * everywhere: alt text, caption and credit. It lives in a folder (AssetFolder) and can
 * carry any number of coloured tags (AssetTag).
 *
 * Most assets are images, but any file of an allowed type can be uploaded (AssetType);
 * the MIME type is kept on the asset so the library can filter by it and image fields
 * only offer images.
 *
 * Blocks store an asset's id rather than a file path, so replacing the file or fixing
 * its alt text updates every page that uses it.
 */
#[Fillable(['title', 'disk', 'access', 'mime_type', 'alt', 'caption', 'folder_id', 'credit', 'uploaded_by_id', 'focal_point'])]
class Asset extends Model implements HasMedia
{
    use HasTranslations, HasUuids, InteractsWithMedia, SoftDeletes;

    /**
     * Read and written in the content language: the page's on the website. The title is the
     * library's own name for the file, the same in every language.
     *
     * @var array<int, string>
     */
    public array $translatable = ['alt', 'caption'];

    protected function casts(): array
    {
        return [
            'focal_point' => 'array',
        ];
    }

    public function getLocale(): string
    {
        return $this->translationLocale ?: ContentLocale::current();
    }

    public const COLLECTION = 'file';

    /**
     * Images that get resized copies (thumbnails, responsive widths). SVGs scale anyway.
     *
     * @var array<int, string>
     */
    public const RASTER_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

    protected static function booted(): void
    {
        // On the disk new files go to, unless said otherwise.
        static::creating(function (Asset $asset): void {
            $asset->disk ??= static::currentDisk();
        });

        // In the trash the file and its renditions stay, ready to restore; deleted for good, they go.
        static::forceDeleting(function (Asset $asset): void {
            Renditions::purge($asset);
            $asset->accessRules()->delete();
        });
    }

    /**
     * The disk the media library puts files on now (media-library.disk_name).
     */
    public static function currentDisk(): string
    {
        return (string) config('media-library.disk_name');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLECTION)
            ->singleFile()
            ->acceptsMimeTypes(AssetType::allowedMimeTypes());
    }

    public function registerMediaConversions(?SpatieMedia $media = null): void
    {
        if ($media !== null && ! in_array($media->mime_type, self::RASTER_MIME_TYPES, true)) {
            return;
        }

        // Already encoded at the quality we want: the WebP optimizer would encode them again at its
        // slowest setting (seconds per image) for no gain. Built in: the library's thumbnail and the
        // share image. The rest are the formats set up under Media library › Formats.
        $this->crop($this->addMediaConversion('thumb'), 480, 360)->format('webp')->quality(80)->nonOptimized();

        foreach (ImageFormat::cached() as $format) {
            $conversion = $this->addMediaConversion($format->handle);

            ($format->crops() ? $this->crop($conversion, $format->width, $format->height) : $conversion->fit(Fit::Max, $format->width, $format->width * 2))
                ->format($format->file_format->value)
                ->quality($format->quality)
                ->nonOptimized();
        }

        $this->crop($this->addMediaConversion('og'), 1200, 630)->format('jpg')->quality(85);
    }

    /**
     * Cropped to exactly this size, around the focal point when the image has one (so the
     * subject stays in), else from the middle.
     */
    protected function crop(Conversion $conversion, int $width, int $height): Conversion
    {
        ['width' => $originalWidth, 'height' => $originalHeight] = $this->dimensions();
        $focal = $this->focal_point;

        if (! is_array($focal) || ! $originalWidth || ! $originalHeight) {
            return $conversion->fit(Fit::Crop, $width, $height);
        }

        return $conversion->focalCropAndResize($width, $height, (int) round($focal['x'] * $originalWidth), (int) round($focal['y'] * $originalHeight));
    }

    /**
     * Its own access, when it has some ("custom"); otherwise it follows its folder.
     */
    public function accessRules(): MorphMany
    {
        return $this->morphMany(AssetAccessRule::class, 'accessible');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(AssetFolder::class, 'folder_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(AssetTag::class)->orderBy('name');
    }

    /**
     * Words in the title, alt text or caption, or the name of the image's folder or one of its tags.
     * Shared by the media library, the image picker and the AI assistant's list_images.
     */
    #[Scope]
    protected function search(Builder $query, string $search): void
    {
        $needle = '%'.mb_strtolower($search).'%';

        $query->where(fn (Builder $query) => $query
            ->where('title', 'like', "%{$search}%")
            // Alt text and captions are kept per language (JSON): search each language's text,
            // in any case, never the language codes around it.
            ->orWhere(function (Builder $query) use ($needle): void {
                foreach (array_keys(Locale::options()) ?: ['en'] as $code) {
                    foreach (['alt', 'caption'] as $column) {
                        $query->orWhereRaw('lower('.$query->getGrammar()->wrap("{$column}->{$code}").') like ?', [$needle]);
                    }
                }
            })
            // The file's own name, as uploaded ("Q3 report.pdf").
            ->orWhereHas('media', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('file_name', 'like', "%{$search}%"))
            ->orWhereHas('folder', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
            ->orWhereHas('tags', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")));
    }

    /**
     * Only assets of these types (none given: every type).
     */
    #[Scope]
    protected function ofType(Builder $query, AssetType ...$types): void
    {
        if ($types === []) {
            return;
        }

        $query->where(function (Builder $query) use ($types): void {
            foreach ($types as $type) {
                $query->orWhere(fn (Builder $query) => $type->scope($query));
            }
        });
    }

    public function type(): AssetType
    {
        return AssetType::fromMimeType($this->mime_type);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(AssetUsage::class);
    }

    public function file(): ?SpatieMedia
    {
        return $this->getFirstMedia(self::COLLECTION);
    }

    /**
     * A URL for the given size (thumb, og, or a format's handle such as md), or the original
     * (null). A format that has since been removed falls back to the largest responsive size,
     * so code asking for it keeps getting a sensible image.
     */
    public function url(?string $size = 'md'): ?string
    {
        $file = $this->file();

        if ($file === null) {
            return null;
        }

        if ($size !== null && ! $file->hasGeneratedConversion($size) && ! in_array($size, ['thumb', 'og', ...ImageFormat::cached()->pluck('handle')], true)) {
            $size = collect(ImageFormat::responsiveWidths())->keys()->reverse()->first(fn (string $handle): bool => $file->hasGeneratedConversion($handle));
        }

        return $size !== null && $file->hasGeneratedConversion($size) ? $file->getUrl($size) : $file->getUrl();
    }

    /**
     * The file's lasting address on the site (/files/{id}), for links: it redirects to the
     * file as it is stored now, so links survive the file being replaced.
     */
    public function linkPath(): string
    {
        return route('files.show', $this, absolute: false);
    }

    /**
     * The srcset for responsive images: every generated width.
     */
    public function srcset(): ?string
    {
        $file = $this->file();

        if ($file === null) {
            return null;
        }

        $sources = collect(ImageFormat::responsiveWidths())
            ->filter(fn (int $width, string $name): bool => $file->hasGeneratedConversion($name))
            ->map(fn (int $width, string $name): string => $file->getUrl($name).' '.$width.'w');

        return $sources->isEmpty() ? null : $sources->implode(', ');
    }

    public function isImage(): bool
    {
        return $this->type() === AssetType::Image;
    }

    /**
     * Width and height of the original, read once on upload.
     *
     * @return array{width: ?int, height: ?int}
     */
    public function dimensions(): array
    {
        return [
            'width' => $this->file()?->getCustomProperty('width'),
            'height' => $this->file()?->getCustomProperty('height'),
        ];
    }
}
