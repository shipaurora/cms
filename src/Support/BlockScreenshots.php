<?php

namespace Aurora\Support;

use Aurora\Blocks\Blocks;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Spatie\Browsershot\Browsershot;

/**
 * Screenshots of blocks for the visual editor's block list, all made the same way: the
 * block alone on its sample page (Block::sample()), seen in a 1600×900 window and kept as
 * an 800×450 WebP. A coding agent takes them with its own browser and hands them to
 * aurora:block-previews --from; Aurora takes them itself with Browsershot, when the site
 * has installed it (spatie/browsershot, which is optional).
 */
class BlockScreenshots
{
    public const WINDOW = [1600, 900];

    public const SIZE = [800, 450];

    /**
     * Takes the picture instead of a browser, in tests: fn (string $url): string (image bytes).
     */
    protected static ?Closure $camera = null;

    /**
     * Sets up Browsershot for this machine: fn (Browsershot $shot): Browsershot.
     */
    protected static ?Closure $setUp = null;

    /**
     * The blocks with no picture at all: no screenshot of the site's, none uploaded in
     * Settings › Blocks and none of their own.
     *
     * @return array<int, string> block types
     */
    public static function missing(): array
    {
        return array_values(array_filter(array_keys(Blocks::all()), fn (string $type): bool => Blocks::preview($type) === null));
    }

    /**
     * The block alone, at an address that works without signing in: for a while, or ($minutes
     * null) always the same address, for the block list's live picture, which must not
     * reload whenever the editor redraws. Either way it shows only the block's example data.
     */
    public static function sampleUrl(string $type, ?int $minutes = 120): string
    {
        return $minutes === null
            ? URL::signedRoute('aurora.blocks.sample', ['type' => $type])
            : URL::temporarySignedRoute('aurora.blocks.sample', now()->addMinutes(max(1, $minutes)), ['type' => $type]);
    }

    /**
     * Whether Aurora can take screenshots itself.
     */
    public static function canCapture(): bool
    {
        return static::$camera !== null || class_exists(Browsershot::class);
    }

    /**
     * The block's screenshot, taken from its sample page, as an 800×450 WebP.
     */
    public static function capture(string $type): string
    {
        $url = static::sampleUrl($type, 10);

        if (static::$camera !== null) {
            return static::toWebp((static::$camera)($url));
        }

        if (! class_exists(Browsershot::class)) {
            throw new RuntimeException('Taking screenshots needs spatie/browsershot: composer require spatie/browsershot, and Chrome or Chromium (npm install puppeteer).');
        }

        [$width, $height] = static::WINDOW;

        $shot = Browsershot::url($url)
            ->windowSize($width, $height)
            ->ignoreHttpsErrors()
            ->waitUntilNetworkIdle()
            ->setScreenshotType('png');

        return static::toWebp((static::$setUp ? (static::$setUp)($shot) : $shot)->screenshot());
    }

    /**
     * Any image (a screenshot's bytes) as the block list wants it: filled to 800×450,
     * cut from the top (where the block starts), as WebP.
     */
    public static function toWebp(string $image): string
    {
        $source = @imagecreatefromstring($image);

        if ($source === false) {
            throw new RuntimeException('That isn\'t an image Aurora can read (PNG, JPEG or WebP).');
        }

        [$width, $height] = static::SIZE;
        $scale = max($width / imagesx($source), $height / imagesy($source));
        $cropWidth = (int) round($width / $scale);
        $cropHeight = (int) round($height / $scale);

        $target = imagecreatetruecolor($width, $height);
        imagecopyresampled($target, $source, 0, 0, (int) ((imagesx($source) - $cropWidth) / 2), 0, $width, $height, $cropWidth, $cropHeight);

        ob_start();
        imagewebp($target, null, 82);

        return (string) ob_get_clean();
    }

    /**
     * Keep it with the site's code, resources/block-previews/{type}.webp, to commit.
     *
     * @return string the file
     */
    public static function saveToSite(string $type, string $webp): string
    {
        $file = resource_path("block-previews/{$type}.webp");
        File::ensureDirectoryExists(dirname($file));
        File::put($file, $webp);

        return $file;
    }

    /**
     * Keep it as if uploaded in Settings › Blocks: on a live site, where the code isn't
     * changed.
     */
    public static function saveAsUploaded(string $type, string $webp): void
    {
        Blocks::putUploadedPreview($type, $webp);
    }

    /**
     * Where Browsershot finds Chrome and Node on this machine, from a service provider:
     * BlockScreenshots::using(fn (Browsershot $shot) => $shot->setChromePath('/usr/bin/chromium')).
     *
     * @param  (Closure(Browsershot $shot): Browsershot)|null  $setUp
     */
    public static function using(?Closure $setUp): void
    {
        static::$setUp = $setUp;
    }

    /**
     * @param  (Closure(string $url): string)|null  $camera
     */
    public static function fake(?Closure $camera = null): void
    {
        static::$camera = $camera;
    }
}
