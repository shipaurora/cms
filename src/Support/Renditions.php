<?php

namespace Aurora\Support;

use Aurora\Models\Asset;
use Aurora\Models\ImageFormat;
use Illuminate\Support\Facades\Storage;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\FlipDirection;
use Spatie\Image\Enums\Orientation;
use Spatie\Image\Image;
use Throwable;

/**
 * Edited versions of library images, made on first use and cached on the media disk.
 *
 * Rendered from the untouched original in a fixed order (rotate, flip, crop, tone, size)
 * and stored at renditions/{asset}/{file version}-{edits}-{size}.webp, so a new original
 * or different edits simply produce a new file. Works on any disk, S3 included.
 */
class Renditions
{
    /**
     * Widths per size name: the thumbnail, every format (Media library › Formats) and the
     * share image.
     *
     * @return array<string, int>
     */
    public static function widths(): array
    {
        return ['thumb' => 480, ...ImageFormat::cached()->mapWithKeys(fn (ImageFormat $format): array => [$format->handle => $format->width])->all(), 'og' => 1200];
    }

    public static function url(Asset $asset, ImageEdits $edits, ?string $size = 'md'): ?string
    {
        $file = $asset->file();

        if ($file === null || ! $asset->isImage() || $file->mime_type === 'image/svg+xml') {
            return $asset->url($size);
        }

        $widths = static::widths();
        $size = array_key_exists((string) $size, $widths) ? $size : (array_key_last(ImageFormat::responsiveWidths()) ?? 'og');
        $disk = Storage::disk($file->disk);
        $path = 'renditions/'.$asset->id.'/'.substr(md5($file->id.$file->updated_at), 0, 8).'-'.$edits->hash().'-'.$size.'.webp';

        if (! $disk->exists($path)) {
            try {
                $disk->put($path, static::render($disk->get($file->getPathRelativeToRoot()), $edits, $widths[$size]));
            } catch (Throwable $exception) {
                report($exception);

                return $asset->url($size);
            }
        }

        return $disk->url($path);
    }

    public static function srcset(Asset $asset, ImageEdits $edits): string
    {
        return collect(ImageFormat::responsiveWidths())
            ->map(fn (int $width, string $name): string => static::url($asset, $edits, $name).' '.$width.'w')
            ->implode(', ');
    }

    /**
     * Forget every rendition of an asset (its original was replaced or it was deleted).
     */
    public static function purge(Asset $asset): void
    {
        $disk = Storage::disk(config('media-library.disk_name'));
        $disk->deleteDirectory('renditions/'.$asset->id);
    }

    protected static function render(string $original, ImageEdits $edits, int $width): string
    {
        $source = tempnam(sys_get_temp_dir(), 'rendition');
        $target = $source.'.webp';
        file_put_contents($source, $original);

        try {
            $image = Image::load($source);

            if ($edits->rotate !== 0) {
                $image->orientation(Orientation::from($edits->rotate));
            }

            if ($edits->flipX || $edits->flipY) {
                $image->flip(match (true) {
                    $edits->flipX && $edits->flipY => FlipDirection::Both,
                    $edits->flipX => FlipDirection::Horizontal,
                    default => FlipDirection::Vertical,
                });
            }

            if ($edits->crop !== null) {
                $w = $image->getWidth();
                $h = $image->getHeight();

                $image->manualCrop(
                    max(1, (int) round($edits->crop['width'] * $w)),
                    max(1, (int) round($edits->crop['height'] * $h)),
                    (int) round($edits->crop['x'] * $w),
                    (int) round($edits->crop['y'] * $h),
                );
            }

            if ($edits->exposure !== 0) {
                $image->brightness($edits->exposure);
            }

            if ($edits->contrast !== 0) {
                $image->contrast($edits->contrast);
            }

            if ($edits->grayscale) {
                $image->greyscale();
            }

            $image->fit(Fit::Max, $width, $width * 3)->quality(82)->save($target);

            return (string) file_get_contents($target);
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }
}
