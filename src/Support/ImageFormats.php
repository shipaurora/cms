<?php

namespace Aurora\Support;

use Aurora\Models\Asset;
use Aurora\Models\ImageFormat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Saving the formats set up under Media library › Formats, and bringing the library's
 * images up to date: the files of a removed or changed format go, and changed and new
 * formats are made for every image in the background. The versions edited for one block
 * are made again, at the new sizes, the next time they're shown.
 */
class ImageFormats
{
    /**
     * The custom property on an image's file listing the formats waiting to be made for it.
     */
    public const PENDING = 'pending_formats';

    /**
     * Save the formats as they are listed (new ones without an id), in that order.
     *
     * @param  array<int, array{id?: ?string, name: string, handle: string, width: int|string, height?: int|string|null, file_format: string, quality: int|string, is_responsive?: bool}>  $rows
     * @return array{changed: array<int, string>, removed: array<int, string>}
     */
    public static function save(array $rows): array
    {
        $existing = ImageFormat::query()->get()->keyBy('id');
        $kept = [];
        $changed = [];

        foreach (array_values($rows) as $index => $row) {
            $attributes = [
                'name' => $row['name'],
                'width' => (int) $row['width'],
                'height' => filled($row['height'] ?? null) ? (int) $row['height'] : null,
                'file_format' => $row['file_format'],
                'quality' => (int) $row['quality'],
                'is_responsive' => (bool) ($row['is_responsive'] ?? false),
                'sort' => $index + 1,
            ];
            $format = $existing->get($row['id'] ?? '');

            if ($format === null) {
                $changed[] = ['new' => [...$attributes, 'handle' => $row['handle']]];

                continue;
            }

            $kept[] = $format->id;
            $format->fill($attributes);

            // A different size or kind of file: its images are made again.
            if ($format->isDirty(['width', 'height', 'file_format', 'quality'])) {
                $changed[] = ['format' => $format];
            } else {
                $format->save();
            }
        }

        $removed = $existing->except($kept);
        $redone = collect($changed)->pluck('format')->filter();

        // While their old settings are still known: the files' names and extensions come from them.
        static::forget([...$removed->pluck('handle'), ...$redone->pluck('handle')]);

        DB::transaction(function () use ($removed, $changed): void {
            $removed->each->delete();

            foreach ($changed as $change) {
                isset($change['new']) ? ImageFormat::query()->create($change['new']) : $change['format']->save();
            }
        });

        $handles = collect($changed)->map(fn (array $change): string => $change['new']['handle'] ?? $change['format']->handle)->all();

        if ($handles !== [] || $removed->isNotEmpty()) {
            static::markPending($handles);
            dispatch(fn () => static::regenerate($handles));
        }

        return ['changed' => $handles, 'removed' => $removed->pluck('handle')->values()->all()];
    }

    /**
     * Add a format (no $format) or change one, keeping the others as they are.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{changed: array<int, string>, removed: array<int, string>}
     */
    public static function put(?ImageFormat $format, array $attributes): array
    {
        $rows = static::rows();

        if ($format === null) {
            $rows[] = ['id' => null, ...$attributes];
        } else {
            $rows = array_map(fn (array $row): array => $row['id'] === $format->id ? [...$row, ...$attributes, 'id' => $format->id, 'handle' => $format->handle] : $row, $rows);
        }

        return static::save($rows);
    }

    /**
     * @return array{changed: array<int, string>, removed: array<int, string>}
     */
    public static function remove(ImageFormat $format): array
    {
        return static::save(array_values(array_filter(static::rows(), fn (array $row): bool => $row['id'] !== $format->id)));
    }

    /**
     * The formats as rows for save(), in their order.
     *
     * @return array<int, array{id: ?string, name: string, handle: string, width: int, height: ?int, file_format: string, quality: int, is_responsive: bool}>
     */
    public static function rows(): array
    {
        return ImageFormat::query()->orderBy('sort')->orderBy('width')->get()->map(fn (ImageFormat $format): array => [
            'id' => $format->id,
            'name' => $format->name,
            'handle' => $format->handle,
            'width' => $format->width,
            'height' => $format->height,
            'file_format' => $format->file_format->value,
            'quality' => $format->quality,
            'is_responsive' => $format->is_responsive,
        ])->all();
    }

    /**
     * Delete these formats' files from every image.
     *
     * @param  array<int, string>  $handles
     */
    public static function forget(array $handles): void
    {
        if ($handles === []) {
            return;
        }

        static::images()->each(function (Media $media) use ($handles): void {
            foreach ($handles as $handle) {
                if (! $media->hasGeneratedConversion($handle)) {
                    continue;
                }

                rescue(fn () => Storage::disk($media->conversions_disk)->delete($media->getPathRelativeToRoot($handle)), report: false);
                $generated = $media->generated_conversions;
                unset($generated[$handle]);
                $media->generated_conversions = $generated;
                $media->saveQuietly();
            }
        });
    }

    /**
     * Make these formats for every image, and let the block versions be made again.
     *
     * @param  array<int, string>  $handles
     */
    public static function regenerate(array $handles): void
    {
        static::images()->each(function (Media $media) use ($handles): void {
            if ($media->model instanceof Asset) {
                Renditions::purge($media->model);
            }

            if ($handles === []) {
                return;
            }

            try {
                app(FileManipulator::class)->createDerivedFiles($media, $handles);
            } catch (Throwable $exception) {
                report($exception);
            }

            // Made, or failed and so missing: no longer waiting either way.
            $media->refresh();
            $media->setCustomProperty(self::PENDING, array_values(array_diff((array) $media->getCustomProperty(self::PENDING, []), $handles)));
            $media->saveQuietly();
        });
    }

    /**
     * Note on every image that these formats are on their way, for the Formats tab.
     *
     * @param  array<int, string>  $handles
     */
    protected static function markPending(array $handles): void
    {
        if ($handles === []) {
            return;
        }

        static::images()->each(function (Media $media) use ($handles): void {
            $media->setCustomProperty(self::PENDING, array_values(array_unique([...(array) $media->getCustomProperty(self::PENDING, []), ...$handles])));
            $media->saveQuietly();
        });
    }

    /**
     * Make one image's formats again (all of them, or just these), and let its block versions
     * be made again too.
     *
     * @param  array<int, string>  $handles
     */
    public static function regenerateImage(Asset $asset, array $handles = []): void
    {
        Renditions::purge($asset);

        if (($media = $asset->file()) !== null && in_array($media->mime_type, Asset::RASTER_MIME_TYPES, true)) {
            app(FileManipulator::class)->createDerivedFiles($media, $handles);
        }
    }

    /**
     * The library's images that get resized copies.
     *
     * @return LazyCollection<int, Media>
     */
    protected static function images(): LazyCollection
    {
        return config('media-library.media_model', Media::class)::query()
            ->where('collection_name', Asset::COLLECTION)
            ->whereIn('mime_type', Asset::RASTER_MIME_TYPES)
            ->with('model')
            ->lazyById();
    }
}
