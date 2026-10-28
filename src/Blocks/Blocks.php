<?php

namespace Aurora\Blocks;

use Aurora\Models\Page;
use Aurora\Models\Setting;
use CarlJanzell\FilamentPageBuilder\BlockRegistries;
use CarlJanzell\FilamentPageBuilder\FilamentPageBuilderPlugin;
use CarlJanzell\FilamentPageBuilder\PageBuilder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * The blocks editors can put on a page. A website registers its own (each a subclass of
 * Aurora\Blocks\Block, with its Blade view) from a service provider:
 *
 *     Blocks::register([HeroBlock::class, CardBlock::class]);
 *
 * Blocks in the folder named by aurora.block_path are found on their own (discover()), so
 * listing them is only needed to set their order in the palette. Aurora hands them to
 * whatever builds its pages (today the page builder package), so websites never talk to
 * that directly and it can change underneath them.
 */
class Blocks
{
    /**
     * Screenshots uploaded in Settings › Blocks, by block type: files on the public disk
     * (like people's avatars), not in the media library.
     */
    public const PREVIEWS_SETTING = 'block_previews';

    /**
     * The panel Aurora's editor runs in.
     */
    protected const PANEL = 'admin';

    /**
     * @var array<string, class-string<Block>>
     */
    protected static array $blocks = [];

    /**
     * Blocks a site left out (forget()): the page builder's own, by type.
     *
     * @var array<string, true>
     */
    protected static array $forgotten = [];

    /**
     * Add blocks, in the order the block palette shows them. Registering a block's type
     * again replaces it.
     *
     * @param  array<int, class-string<Block>>  $blocks
     */
    public static function register(array $blocks): void
    {
        foreach ($blocks as $block) {
            if (! is_subclass_of($block, Block::class)) {
                throw new InvalidArgumentException("[{$block}] must extend ".Block::class.'.');
            }

            static::$blocks[$block::type()] = $block;
            unset(static::$forgotten[$block::type()]);
        }

        app(BlockRegistries::class)->for(self::PANEL)->register($blocks);
    }

    /**
     * Leave blocks out of the palette, by type: the page builder's own (section, text, list,
     * quote, image, button, video, embed, spacer, divider, shortcode, code) that a site's
     * stylesheet doesn't style, or one registered before. Pages that have one draw nothing in
     * its place. Registering the type again brings it back.
     *
     *     Blocks::forget('text', 'quote', 'divider');
     */
    public static function forget(string ...$types): void
    {
        foreach ($types as $type) {
            static::$forgotten[$type] = true;
            unset(static::$blocks[$type]);
        }

        // Given to the page builder already (its panel built first): taken back from it too.
        $registry = app(BlockRegistries::class)->for(self::PANEL);
        (fn () => $this->blocks = array_diff_key($this->blocks, array_flip($types)))->call($registry);
    }

    /**
     * The page builder's own blocks, less those a site left out (forget()): what Aurora's
     * panel offers before the website's own.
     *
     * @return array<int, class-string>
     */
    public static function library(): array
    {
        return array_values(array_filter(
            FilamentPageBuilderPlugin::layoutBlockClasses(),
            fn (string $block): bool => ! isset(static::$forgotten[$block::type()]),
        ));
    }

    /**
     * Register every block class in a folder (and its subfolders) that isn't registered yet,
     * by file name. Abstract classes, traits, interfaces and other classes are left alone.
     * Blocks registered by hand keep their place in the palette; these follow them.
     *
     * @return array<int, class-string<Block>>
     */
    public static function discover(string $path, ?string $namespace = null): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $namespace = trim($namespace ?? static::namespaceFor($path), '\\');
        $found = [];

        foreach ((new Finder)->files()->in($path)->name('*.php')->sortByName() as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = $namespace.'\\'.$relative;

            if (! class_exists($class) || ! is_subclass_of($class, Block::class) || ! (new ReflectionClass($class))->isInstantiable()) {
                continue;
            }

            if (! isset(static::$blocks[$class::type()])) {
                $found[] = $class;
            }
        }

        if ($found !== []) {
            static::register($found);
        }

        return $found;
    }

    /**
     * The namespace of a folder inside the app (app/Blocks is App\Blocks).
     */
    public static function namespaceFor(string $path): string
    {
        $app = rtrim(app_path(), DIRECTORY_SEPARATOR);
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        if (! str_starts_with($path.DIRECTORY_SEPARATOR, $app.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException("[{$path}] is outside the app folder: set aurora.block_namespace too.");
        }

        $relative = trim(substr($path, strlen($app)), DIRECTORY_SEPARATOR);

        return rtrim(app()->getNamespace().str_replace(DIRECTORY_SEPARATOR, '\\', $relative), '\\');
    }

    /**
     * @return array<string, class-string<Block>>
     */
    public static function all(): array
    {
        return static::$blocks;
    }

    /**
     * A block's picture for the visual editor's block list: the screenshot uploaded for it in
     * Settings › Blocks, else the block's own (Block::preview()), else none.
     */
    public static function preview(string $type): ?string
    {
        $uploaded = (Setting::get(self::PREVIEWS_SETTING) ?? [])[$type] ?? null;

        if (filled($uploaded)) {
            return Storage::disk('public')->url($uploaded);
        }

        $block = static::find($type);

        return $block === null ? null : $block::preview();
    }

    /**
     * Keep a screenshot uploaded for a block (a file on the public disk), or none: the file of
     * the one it replaces goes.
     */
    public static function putPreview(string $type, ?string $path): void
    {
        $previews = Setting::get(self::PREVIEWS_SETTING) ?? [];

        if (filled($previews[$type] ?? null) && $previews[$type] !== $path) {
            Storage::disk('public')->delete($previews[$type]);
        }

        $previews[$type] = $path;

        Setting::put([self::PREVIEWS_SETTING => array_filter($previews)]);
    }

    /**
     * Keep a picture of a block as if uploaded in Settings › Blocks.
     */
    public static function putUploadedPreview(string $type, string $webp): void
    {
        $path = 'block-previews/'.$type.'-'.Str::random(8).'.webp';
        Storage::disk('public')->put($path, $webp);

        static::putPreview($type, $path);
    }

    /**
     * The site's own screenshot of a block: resources/block-previews/{type}.webp (or .png,
     * .jpg), committed with the site's code. Null when there's none.
     */
    public static function screenshotFile(string $type): ?string
    {
        if (preg_match('/^[a-z0-9-]+$/', $type) !== 1) {
            return null;
        }

        foreach (['webp', 'png', 'jpg'] as $extension) {
            if (is_file($file = resource_path("block-previews/{$type}.{$extension}"))) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Where the visual editor loads that screenshot from; its address changes with the file.
     */
    public static function screenshotUrl(string $type): ?string
    {
        $file = static::screenshotFile($type);

        return $file === null ? null : route('aurora.blocks.screenshot', ['type' => $type, 'v' => filemtime($file)]);
    }

    /**
     * The block class for a type, or null when no such block is registered.
     *
     * @return class-string<Block>|null
     */
    public static function find(string $type): ?string
    {
        return static::$blocks[$type] ?? null;
    }

    /**
     * Whether a block is being drawn in the visual editor (so it can skip animations and
     * show placeholders for empty text), rather than on the website.
     */
    public static function isEditing(): bool
    {
        return PageBuilder::isEditing();
    }

    /**
     * The page being drawn: on the website, in previews and on the visual editor's canvas.
     * Null when blocks are drawn for no page (a block's preview in the palette).
     */
    public static function page(): ?Page
    {
        $page = View::shared('currentPage');

        return $page instanceof Page ? $page : null;
    }

    /**
     * How deep the block being drawn sits: 0 straight on the page, 1 in a column (or any
     * container's slot), and so on.
     */
    public static function depth(): int
    {
        return InlineEditing::depth();
    }

    /**
     * Whether the block being drawn is in a container (a column, a band) rather than straight
     * on the page: a site that wraps top-level blocks in its page container skips those.
     */
    public static function isNested(): bool
    {
        return static::depth() > 0;
    }

    /**
     * A container block's slot, with the blocks in it (for a Contracts\Container's view).
     */
    public static function slot(string $name): string
    {
        return PageBuilder::slot($name);
    }
}
