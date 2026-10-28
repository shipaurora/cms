<?php

namespace Aurora\Support;

use Aurora\Enums\AssetType;
use Aurora\Jobs\TagImage;
use Aurora\Models\Asset;
use Aurora\Models\AssetTag;
use Aurora\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Tags the AI assistant picks by itself (Settings › AI Assistant), as it writes alt text
 * (ImageDescriptions): for each image added to the media library, and for the images already
 * there without tags, on the queue. It reuses the library's tags where they fit and adds a new
 * one only when none does. Only for images without tags: it never changes tags someone chose.
 */
class ImageTags
{
    public const SETTING = 'copilot.tag_images';

    /**
     * The most tags the assistant gives one image.
     */
    public const MAX_TAGS = 5;

    /**
     * The longest tag name the assistant may add.
     */
    public const MAX_LENGTH = 40;

    /**
     * Switched on, and the assistant is there to do it.
     */
    public static function isOn(): bool
    {
        try {
            return Setting::get(self::SETTING) === true && Copilot::isConfigured();
        } catch (QueryException) {
            // No settings table yet (fresh install, migrations running).
            return false;
        }
    }

    /**
     * Have the assistant tag a new image, when it's on and the image has no tags.
     */
    public static function queue(Asset $asset): void
    {
        if (static::isOn() && static::needsTags($asset)) {
            TagImage::dispatch($asset->getKey());
        }
    }

    /**
     * Have the assistant tag every image without tags: how many it was given.
     */
    public static function queueMissing(): int
    {
        if (! static::isOn()) {
            return 0;
        }

        $count = 0;

        static::missing()->select('id')->each(function (Asset $asset) use (&$count): void {
            TagImage::dispatch($asset->getKey());
            $count++;
        });

        return $count;
    }

    /**
     * Images without tags.
     *
     * @return Builder<Asset>
     */
    public static function missing(): Builder
    {
        return Asset::query()->ofType(AssetType::Image)->whereDoesntHave('tags');
    }

    /**
     * Have the assistant tag the given images that have no tags, because someone asked (the
     * media library's bulk action), with their instruction: how many it was given.
     *
     * @param  iterable<Asset>  $assets
     */
    public static function queueFor(iterable $assets, ?string $instruction = null): int
    {
        $count = 0;

        foreach ($assets as $asset) {
            if (static::needsTags($asset)) {
                TagImage::dispatch($asset->getKey(), $instruction, asked: true);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Give an image its tags, unless it has some by now. Done by itself only while the setting
     * is on; when someone $asked, whenever the assistant is set up.
     */
    public static function tag(Asset $asset, ?string $instruction = null, bool $asked = false): void
    {
        if (! ($asked ? Copilot::isConfigured() : static::isOn()) || ! static::needsTags($asset) || ($image = AiWriter::imageOf($asset)) === null) {
            return;
        }

        Copilot::applyConfig();

        $existing = AssetTag::query()->orderBy('name')->pluck('name');
        $answer = AiWriter::write(
            field: 'Tags',
            context: array_filter([
                'Purpose' => 'Tags that sort this image in the media library: 1 to '.self::MAX_TAGS.' short tags, separated by commas, each one or two words.',
                'Existing tags' => $existing->isEmpty() ? null : $existing->implode(', '),
                'Rule' => 'Use the existing tags, written exactly as they are, whenever one fits. Add a new tag only when none of them does.',
                'Title' => (string) $asset->title,
            ]),
            instruction: $instruction,
            images: [$image],
        );

        $names = static::names($answer);

        // Someone may have tagged it while the assistant was looking.
        if ($names === [] || ! static::needsTags($asset->refresh())) {
            return;
        }

        $asset->tags()->sync(collect($names)->map(fn (string $name): string => static::findOrCreate($name)->getKey())->all());
    }

    /**
     * The tag names in the assistant's answer: trimmed, without duplicates, at most MAX_TAGS.
     *
     * @return array<int, string>
     */
    public static function names(string $answer): array
    {
        return collect(preg_split('/[,\n]+/', $answer))
            ->map(fn (string $name): string => Str::limit(trim($name, " \t.-•*\"'“”«»"), self::MAX_LENGTH, ''))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->take(self::MAX_TAGS)
            ->values()
            ->all();
    }

    /**
     * The library's tag of that name, whatever its capitals, or a new one.
     */
    protected static function findOrCreate(string $name): AssetTag
    {
        return AssetTag::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? AssetTag::query()->create(['name' => $name]);
    }

    public static function needsTags(Asset $asset): bool
    {
        return $asset->isImage() && ! $asset->tags()->exists();
    }
}
