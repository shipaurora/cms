<?php

namespace Aurora\Support;

use Aurora\Blocks\Blocks;
use Closure;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Page;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\Resource as FilamentResource;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * The website's own screens in Aurora's admin: resources for its models (events, albums)
 * and pages, and the navigation groups they sit in, registered in a service provider's
 * register() method. Not boot(): the admin panel is built while the app's providers boot,
 * before the app's own boot() runs, and keeps the lists it finds then.
 *
 *     AdminPanel::discover(app_path('Filament/Resources'), 'App\Filament\Resources');
 *     AdminPanel::navigationGroup('Events', fn (): string => __('Events'));
 *
 * The admin is per site (/admin/{site}/…): a website's resources show every site's records
 * unless they're registered perSite, for a model that belongs to a site (a site relation).
 */
class AdminPanel
{
    /**
     * Aurora's own groups, in order; a website's go after one of them.
     */
    public const GROUPS = ['Content', 'AI', 'Administration'];

    /**
     * @var array<int, class-string<FilamentResource>>
     */
    protected static array $resources = [];

    /**
     * @var array<int, class-string<Page>>
     */
    protected static array $pages = [];

    /**
     * @var array<string, array{label: string|Closure|null, icon: ?string, after: string}>
     */
    protected static array $groups = [];

    /**
     * @param  array<int, class-string<FilamentResource>>  $resources
     * @param  bool  $perSite  Their models belong to a site (a site relation), each site's own.
     */
    public static function resources(array $resources, bool $perSite = false): void
    {
        foreach ($resources as $resource) {
            if (! $perSite) {
                $resource::scopeToTenant(false);
            }

            static::$resources[] = $resource;
        }

        static::$resources = array_values(array_unique(static::$resources));
    }

    /**
     * @param  array<int, class-string<Page>>  $pages
     */
    public static function pages(array $pages): void
    {
        static::$pages = array_values(array_unique([...static::$pages, ...$pages]));
    }

    /**
     * Every resource and page in a folder (and its subfolders), as resources() and pages().
     *
     * @return array<int, class-string>
     */
    public static function discover(string $path, ?string $namespace = null, bool $perSite = false): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $namespace = trim($namespace ?? Blocks::namespaceFor($path), '\\');
        $resources = [];
        $pages = [];

        foreach ((new Finder)->files()->in($path)->name('*.php')->sortByName() as $file) {
            $class = $namespace.'\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! class_exists($class) || ! (new ReflectionClass($class))->isInstantiable()) {
                continue;
            }

            if (is_subclass_of($class, FilamentResource::class)) {
                $resources[] = $class;
            } elseif (is_subclass_of($class, Page::class) && ! is_subclass_of($class, ResourcePage::class)) {
                $pages[] = $class;
            }
        }

        static::resources($resources, $perSite);
        static::pages($pages);

        return [...$resources, ...$pages];
    }

    /**
     * A navigation group for the website's screens, keyed by the name its resources give
     * ($navigationGroup), after one of Aurora's (Content by default) or one of the website's
     * registered before it; groups after the same one keep the order they're registered in.
     */
    public static function navigationGroup(string $name, string|Closure|null $label = null, ?string $icon = null, string $after = 'Content'): void
    {
        static::$groups[$name] = ['label' => $label, 'icon' => $icon, 'after' => $after];
    }

    /**
     * @return array<int, class-string<FilamentResource>>
     */
    public static function registeredResources(): array
    {
        return static::$resources;
    }

    /**
     * @return array<int, class-string<Page>>
     */
    public static function registeredPages(): array
    {
        return static::$pages;
    }

    /**
     * Aurora's groups and the website's, in order, for the panel.
     *
     * @param  array<string, NavigationGroup>  $aurora  Aurora's own, keyed by name
     * @return array<string, NavigationGroup>
     */
    public static function navigationGroups(array $aurora): array
    {
        $ordered = $aurora;

        foreach (static::$groups as $name => $group) {
            $keys = array_keys($ordered);
            $after = array_search(array_key_exists($group['after'], $ordered) ? $group['after'] : 'Content', $keys, true);
            $position = $after === false ? count($keys) : $after + 1;

            // After the website's groups already there, in the order they were registered.
            while (isset($keys[$position], static::$groups[$keys[$position]])) {
                $position++;
            }

            $navigation = NavigationGroup::make()->label($group['label'] ?? fn (): string => __($name))->icon($group['icon']);
            $ordered = array_slice($ordered, 0, $position, true) + [$name => $navigation] + array_slice($ordered, $position, null, true);
        }

        return $ordered;
    }
}
