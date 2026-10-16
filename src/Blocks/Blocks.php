<?php

namespace Aurora\Blocks;

use CarlJanzell\FilamentPageBuilder\BlockRegistries;
use CarlJanzell\FilamentPageBuilder\PageBuilder;
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
     * The panel Aurora's editor runs in.
     */
    protected const PANEL = 'admin';

    /**
     * @var array<string, class-string<Block>>
     */
    protected static array $blocks = [];

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
        }

        app(BlockRegistries::class)->for(self::PANEL)->register($blocks);
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
     * A container block's slot, with the blocks in it (for a Contracts\Container's view).
     */
    public static function slot(string $name): string
    {
        return PageBuilder::slot($name);
    }
}
