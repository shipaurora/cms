<?php

namespace Aurora\Support;

use Aurora\Enums\AssetType;
use Aurora\Jobs\DescribeImage;
use Aurora\Models\Asset;
use Aurora\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;

/**
 * Alt text the AI assistant writes by itself (Settings › AI Assistant): for each image added
 * to the media library, and for the images already there without it, on the queue so nobody
 * waits for it. Only where there is none yet: it never replaces what someone wrote.
 */
class ImageDescriptions
{
    public const SETTING = 'copilot.describe_images';

    /**
     * Guidance for the assistant, the same as "Write with AI" on the alt text field.
     */
    public const GUIDANCE = 'Alt text for screen readers: what the image shows, in one plain sentence, without "image of".';

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
     * Have the assistant describe a new image, when it's on and the image has no alt text.
     */
    public static function queue(Asset $asset): void
    {
        if (static::isOn() && static::needsDescription($asset)) {
            DescribeImage::dispatch($asset->getKey());
        }
    }

    /**
     * Have the assistant describe every image without alt text: how many it was given.
     */
    public static function queueMissing(): int
    {
        if (! static::isOn()) {
            return 0;
        }

        $count = 0;

        static::missing()->select('id')->each(function (Asset $asset) use (&$count): void {
            DescribeImage::dispatch($asset->getKey());
            $count++;
        });

        return $count;
    }

    /**
     * Images without alt text (in the website's language).
     *
     * @return Builder<Asset>
     */
    public static function missing(): Builder
    {
        $alt = 'alt->'.ContentLocale::default();

        return Asset::query()
            ->ofType(AssetType::Image)
            ->where(fn (Builder $query) => $query->whereNull('alt')->orWhereNull($alt)->orWhere($alt, ''));
    }

    /**
     * Write an image's alt text in the website's language, unless it has some by now.
     */
    public static function describe(Asset $asset): void
    {
        if (! static::isOn() || ! static::needsDescription($asset) || ($image = AiWriter::imageOf($asset)) === null) {
            return;
        }

        Copilot::applyConfig();

        $locale = ContentLocale::default();
        $text = AiWriter::write(
            field: 'Alt text',
            context: ['Purpose' => self::GUIDANCE, 'Title' => (string) $asset->title],
            maxLength: 255,
            images: [$image],
            language: $locale,
        );

        // Someone may have written one while the assistant was looking.
        if ($text !== '' && static::needsDescription($asset->refresh())) {
            $asset->setTranslation('alt', $locale, $text)->save();
        }
    }

    protected static function needsDescription(Asset $asset): bool
    {
        return $asset->isImage() && blank($asset->getTranslation('alt', ContentLocale::default(), false));
    }
}
