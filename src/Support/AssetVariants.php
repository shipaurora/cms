<?php

namespace Aurora\Support;

use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Asset;
use Aurora\Models\ImageFormat;
use Aurora\Models\Page;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Every version of a library image: the original and the sizes the library makes from it
 * (Asset::registerMediaConversions), and the versions cropped or adjusted for one block
 * (ImageEdits, rendered by Renditions). Shown on the image's page in the media library.
 */
class AssetVariants
{
    /**
     * Every size, made or not: its status is "ready" (made), "pending" (being made in the
     * background, ImageFormats) or "missing".
     *
     * @return array<int, array{name: string, handle: ?string, purpose: string, status: string, url: ?string, dimensions: ?string, size: ?string, format: ?string}>
     */
    public static function sizes(Asset $asset): array
    {
        $file = $asset->file();

        if ($file === null) {
            return [];
        }

        // The built-in sizes, and the formats set up under Media library › Formats.
        $sizes = [
            '' => [__('Original'), __('As uploaded')],
            'thumb' => [__('Thumbnail'), __('Cropped to 480 × 360, for the library and image pickers')],
            ...ImageFormat::cached()->mapWithKeys(fn (ImageFormat $format): array => [$format->handle => [
                $format->name,
                $format->is_responsive ? __(':size, for responsive images', ['size' => $format->describe()]) : $format->describe(),
            ]])->all(),
            'og' => [__('Share image'), __('Cropped to 1200 × 630, for links shared on social media')],
        ];

        $variants = [];
        $pending = (array) $file->getCustomProperty(ImageFormats::PENDING, []);

        foreach ($sizes as $conversion => [$name, $purpose]) {
            $status = match (true) {
                $conversion === '' || $file->hasGeneratedConversion($conversion) => 'ready',
                in_array($conversion, $pending, true) => 'pending',
                default => 'missing',
            };

            $variants[] = [
                'name' => $name,
                'handle' => $conversion === '' ? null : $conversion,
                'purpose' => $purpose,
                'status' => $status,
                ...($status === 'ready'
                    ? ['url' => $file->getUrl($conversion), ...static::measure($file, $conversion)]
                    : ['url' => null, 'dimensions' => null, 'size' => null, 'format' => null]),
            ];
        }

        return $variants;
    }

    /**
     * The versions of the image edited for one block, wherever it is used.
     *
     * @return array<int, array{page: string, block: string, url: ?string, preview: ?string, description: ?string, editor: string}>
     */
    public static function edits(Asset $asset): array
    {
        // Pages on every site: the library is shared by all of them.
        $pages = Page::acrossSites()->whereKey($asset->usages()->where('usable_type', (new Page)->getMorphClass())->pluck('usable_id'))->get();
        $variants = [];

        foreach ($pages as $page) {
            foreach ($page->blocks ?? [] as $block) {
                foreach (static::editedValues($block['data'] ?? [], $asset->id) as $value) {
                    $edits = ImageEdits::from($value);

                    $variants[] = [
                        'page' => $page->title,
                        'block' => AssetLibrary::describeLocation($block['type'] ?? null),
                        'url' => Renditions::url($asset, $edits, array_key_last(ImageFormat::responsiveWidths()) ?? 'og'),
                        'preview' => Renditions::url($asset, $edits, 'thumb'),
                        'description' => $edits->describe(),
                        'editor' => PageResource::editorUrl($page),
                    ];
                }
            }
        }

        return $variants;
    }

    /**
     * Image field values in a block's data that point at the asset with edits.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function editedValues(mixed $data, string $assetId): array
    {
        if (! is_array($data)) {
            return [];
        }

        if (($data['asset'] ?? null) === $assetId) {
            return ImageEdits::from($data)->isEmpty() ? [] : [$data];
        }

        return array_merge(...array_map(fn (mixed $child): array => static::editedValues($child, $assetId), array_values($data)) ?: [[]]);
    }

    /**
     * @return array{dimensions: ?string, size: ?string, format: string}
     */
    protected static function measure(Media $file, string $conversion): array
    {
        $disk = Storage::disk($conversion === '' ? $file->disk : $file->conversions_disk);
        $path = $file->getPathRelativeToRoot($conversion);
        $bytes = rescue(fn (): ?int => $disk->size($path), null, report: false);
        $dimensions = rescue(fn (): array|false => getimagesizefromstring((string) $disk->get($path)), false, report: false);

        return [
            'dimensions' => $dimensions ? "{$dimensions[0]} × {$dimensions[1]} px" : null,
            'size' => $bytes !== null ? Number::fileSize($bytes, 1) : null,
            'format' => strtoupper(pathinfo($path, PATHINFO_EXTENSION)),
        ];
    }
}
