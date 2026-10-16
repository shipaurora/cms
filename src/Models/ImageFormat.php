<?php

namespace Aurora\Models;

use Aurora\Enums\ImageFileFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Once;

/**
 * A size the media library makes of every image, set up under Media library › Formats: a
 * width (and, to crop, a height), the kind of file and its quality. Responsive formats go
 * in srcset. Thumbnail ('thumb') and Share image ('og') are built in (Asset::registerMediaConversions).
 */
#[Fillable(['name', 'handle', 'width', 'height', 'file_format', 'quality', 'is_responsive', 'sort'])]
class ImageFormat extends Model
{
    use HasUuids;

    /**
     * Names taken by the built-in sizes and the original.
     *
     * @var array<int, string>
     */
    public const RESERVED_HANDLES = ['thumb', 'og', 'original'];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'file_format' => ImageFileFormat::class,
            'quality' => 'integer',
            'is_responsive' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Formats are read for every image: once per request, again after a change.
        static::saved(fn () => Once::flush());
        static::deleted(fn () => Once::flush());
    }

    /**
     * Every format, in order, read once per request.
     *
     * @return Collection<int, ImageFormat>
     */
    public static function cached(): Collection
    {
        return once(fn (): Collection => static::query()->orderBy('sort')->orderBy('width')->get());
    }

    /**
     * The responsive widths, smallest first, by handle: what srcset offers.
     *
     * @return array<string, int>
     */
    public static function responsiveWidths(): array
    {
        return static::cached()
            ->where('is_responsive', true)
            ->sortBy('width')
            ->mapWithKeys(fn (ImageFormat $format): array => [$format->handle => $format->width])
            ->all();
    }

    public function crops(): bool
    {
        return $this->height !== null;
    }

    /**
     * "Up to 1280 px wide" or "Cropped to 1200 × 630".
     */
    public function describe(): string
    {
        return $this->crops()
            ? __('Cropped to :width × :height', ['width' => $this->width, 'height' => $this->height])
            : __('Up to :width px wide', ['width' => $this->width]);
    }
}
