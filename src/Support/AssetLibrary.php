<?php

namespace Aurora\Support;

use Aurora\Enums\AssetType;
use Aurora\Models\Asset;
use Aurora\Models\AssetFolder;
use Aurora\Models\AssetUsage;
use Aurora\Models\Page;
use CarlJanzell\FilamentPageBuilder\Support\MediaUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\FileAdder;

/**
 * The media library's service: creating assets (images or any other allowed file), turning
 * a stored value into an image, and keeping track of where each asset is used.
 *
 * Image fields store an asset id. Older content (and AI assistants) may still hold a
 * file path or an absolute URL, which keep working as before.
 */
class AssetLibrary
{
    /**
     * A file into the library (an import's). With an "id" (a UUID, say one kept from the
     * source), running the import again finds the asset it added instead of a second copy.
     *
     * @param  array<string, mixed>  $attributes  title, alt, caption, credit, folder (a path) or folder_id, id
     */
    public static function fromFile(string $path, array $attributes = []): Asset
    {
        if (filled($attributes['id'] ?? null) && ($existing = Asset::query()->find($attributes['id'])) !== null) {
            return $existing;
        }

        return static::store($attributes, fn (Asset $asset) => $asset->addMedia($path)->preservingOriginal(), basename($path), $path);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromUpload(UploadedFile $file, array $attributes = []): Asset
    {
        return static::store($attributes, fn (Asset $asset) => $asset->addMedia($file), $file->getClientOriginalName(), $file->getRealPath());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  callable(Asset): FileAdder  $adder
     */
    protected static function store(array $attributes, callable $adder, string $fileName, ?string $localPath): Asset
    {
        $title = $attributes['title'] ?? Str::of(pathinfo($fileName, PATHINFO_FILENAME))->replace(['-', '_'], ' ')->squish()->ucfirst()->toString();
        $size = static::imageSize($localPath);

        $asset = DB::transaction(function () use ($attributes, $adder, $title, $size, $localPath): Asset {
            $asset = Asset::query()->forceCreate([
                ...(filled($attributes['id'] ?? null) ? ['id' => (string) $attributes['id']] : []),
                'uploaded_by_id' => auth()->id(),
                ...Arr::only($attributes, ['alt', 'caption', 'credit', 'folder_id']),
                // A photo's credit from its EXIF (Artist, else Copyright), unless one is given.
                ...(blank($attributes['credit'] ?? null) ? ['credit' => ImageMetadata::credit($localPath)] : []),
                // A folder may also be given as a path ("Photos/Norway"), created when missing.
                ...(filled($attributes['folder'] ?? null) ? ['folder_id' => AssetFolder::findOrCreatePath($attributes['folder'])?->id] : []),
                'title' => $title,
            ]);

            $media = $adder($asset)
                ->withCustomProperties(array_filter(['width' => $size[0] ?? null, 'height' => $size[1] ?? null]))
                ->toMediaCollection(Asset::COLLECTION);

            $asset->update(['mime_type' => $media->mime_type]);

            return $asset->refresh();
        });

        // Its alt text, by the AI assistant when it's set to.
        ImageDescriptions::queue($asset);

        return $asset;
    }

    /**
     * Why a file can't go into the library, or null when it can: a type that isn't
     * allowed, or too large for its type. Pass $only to accept just one type (e.g. when
     * replacing an image, which pages show as an image).
     */
    public static function rejectionFor(UploadedFile $file, ?AssetType $only = null): ?string
    {
        $mimeType = $file->getMimeType();
        $type = AssetType::fromMimeType($mimeType);

        if (! in_array($mimeType, AssetType::allowedMimeTypes(), true)) {
            return __('This type of file can’t be uploaded.');
        }

        if ($only !== null && $type !== $only) {
            return __('Choose a file of the same type (:type).', ['type' => mb_strtolower($only->getLabel())]);
        }

        if ($file->getSize() > $type->maxKilobytes() * 1024) {
            return __('Larger than :size.', ['size' => Number::fileSize($type->maxKilobytes() * 1024)]);
        }

        return null;
    }

    /**
     * Width and height of an image file, or false for anything else.
     *
     * @return array<int, mixed>|false
     */
    public static function imageSize(?string $path): array|false
    {
        return $path && is_file($path) && in_array(mime_content_type($path), AssetType::Image->mimeTypes(), true) ? @getimagesize($path) : false;
    }

    /**
     * The library asset behind an image field value (a bare id, or an id with edits).
     */
    public static function find(mixed $value): ?Asset
    {
        $id = ImageEdits::assetId($value);

        if ($id === null) {
            return null;
        }

        return static::loaded($id);
    }

    /**
     * A URL for whatever an image field holds: an asset id (with this block's edits applied),
     * a path on the public disk, or a URL.
     */
    public static function url(mixed $value, ?string $size = 'md'): ?string
    {
        if (is_array($value) && array_is_list($value)) {
            $value = Arr::first($value);
        }

        if ($asset = static::find($value)) {
            // An image field pointing at another kind of file draws nothing.
            if (! $asset->isImage()) {
                return null;
            }

            $edits = ImageEdits::from($value);

            return $edits->isEmpty() ? $asset->url($size) : Renditions::url($asset, $edits, $size);
        }

        return is_string($value) && $value !== '' && ! Str::isUuid($value) ? MediaUrl::public($value) : null;
    }

    public static function srcset(mixed $value): ?string
    {
        $asset = static::find($value);

        if ($asset === null || ! $asset->isImage()) {
            return null;
        }

        $edits = ImageEdits::from($value);

        return $edits->isEmpty() ? $asset->srcset() : Renditions::srcset($asset, $edits);
    }

    public static function alt(mixed $value): ?string
    {
        return static::find($value)?->alt;
    }

    /**
     * Rebuild the usage index for a model whose JSON may reference assets.
     *
     * Every string that is the id of an existing asset counts, wherever it sits: any
     * block, any field, inside repeaters. A field's position is kept for "where".
     *
     * @param  array<string, mixed>  $values  location => value, e.g. ['blocks' => [...], 'og_image' => '...']
     */
    public static function syncUsages(Model $model, array $values): void
    {
        $found = [];
        static::collect($values, '', $found);

        $existing = $found === [] ? collect() : Asset::query()->whereIn('id', array_keys($found))->pluck('id');

        DB::transaction(function () use ($model, $found, $existing): void {
            AssetUsage::query()->whereMorphedTo('usable', $model)->delete();

            foreach ($existing as $assetId) {
                foreach (array_unique($found[$assetId]) as $location) {
                    AssetUsage::query()->create([
                        'asset_id' => $assetId,
                        'usable_type' => $model->getMorphClass(),
                        'usable_id' => $model->getKey(),
                        'location' => $location,
                    ]);
                }
            }
        });
    }

    /**
     * The ids of the library files some content refers to, found the way the usage index
     * finds them (any id anywhere, and /files/{id} links in text).
     *
     * @return array<int, string>
     */
    public static function referencedIds(mixed $values): array
    {
        $found = [];
        static::collect($values, '', $found);

        return $found === [] ? [] : Asset::query()->whereIn('id', array_keys($found))->pluck('id')->all();
    }

    /**
     * @param  array<string, array<int, string>>  $found
     */
    protected static function collect(mixed $value, string $location, array &$found): void
    {
        if (is_string($value) && Str::isUuid($value)) {
            $found[$value][] = $location;

            return;
        }

        // Links to library files inside text (rich text, URL fields) use their lasting address.
        if (is_string($value) && str_contains($value, '/files/')) {
            preg_match_all('#/files/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $value, $matches);

            foreach ($matches[1] as $id) {
                $found[strtolower($id)][] = $location;
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        // Inside a block, the block's type is the most useful "where".
        if (isset($value['type'], $value['data']) && is_string($value['type'])) {
            static::collect($value['data'], $value['type'], $found);

            return;
        }

        foreach ($value as $key => $child) {
            static::collect($child, $location !== '' ? $location : (string) $key, $found);
        }
    }

    /**
     * Each asset loaded once per request (a page shows the same image in several sizes);
     * once() is cleared between requests, queue jobs and tests.
     */
    protected static function loaded(string $id): ?Asset
    {
        return once(fn (): ?Asset => Asset::query()->with('media')->find($id));
    }

    /**
     * Readable label for a usage location: a block type or a page field.
     */
    public static function describeLocation(?string $location): string
    {
        return match ($location) {
            'og_image' => __('Share image'),
            'redirect' => __('Redirect'),
            null, '' => __('Content'),
            default => BlockCatalogue::describe($location)['label'] ?? Str::headline($location),
        };
    }

    /**
     * Pages are the main thing that use assets; anything else can register later.
     *
     * @return array<string, mixed>
     */
    public static function pageValues(Page $page): array
    {
        return ['blocks' => $page->blocks ?? [], 'og_image' => $page->og_image];
    }
}
