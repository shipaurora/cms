<?php

namespace Aurora\Support;

use Aurora\Blocks\Blocks;
use Aurora\Layouts\PageLayout;
use Aurora\Models\Layout;
use Aurora\Models\Page;
use Closure;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Page layouts and the views that draw them. A layout names any Blade view: Aurora's own
 * (aurora::layouts.standard and .blank, published to the site on install to make them its
 * own) or one of the site's. Each gets $page, $layout, $settings, $preview and $embedded.
 *
 * Layouts are added in the admin (Pages › More › Layouts), or defined in code, a site's or
 * a component library's, and registered from a service provider (Aurora\Layouts\PageLayout):
 *
 *     Layouts::register([CampaignLayout::class]);
 *
 * Those are offered with the others and can be the default, but can't be changed or deleted
 * in the admin: each has a row, kept in step with its class (syncRegistered()).
 *
 * A view can ask for fields of its own, for what doesn't fit in a block (a background
 * image): pages drawn with it show them under the layout picker, and they're saved in the
 * page's settings, by view (settings.layouts.<view>: another view's field of the same name
 * can be of another type), and the view gets its own as $settings. In a service provider's boot():
 *
 *     Layouts::schema('layouts.campaign', fn (): array => [
 *         AssetPicker::make('background')->label(__('Background image')),
 *     ]);
 */
class Layouts
{
    /**
     * Each view's fields, by view name.
     *
     * @var array<string, Closure(): array<int, Component>|array<int, Component>>
     */
    protected static array $schemas = [];

    /**
     * Layouts defined in code, by handle.
     *
     * @var array<string, class-string<PageLayout>>
     */
    protected static array $registered = [];

    /**
     * Offer layouts defined in code. Their fields (PageLayout::schema()) are their views'.
     *
     * @param  array<int, class-string<PageLayout>>  $layouts
     */
    public static function register(array $layouts): void
    {
        foreach ($layouts as $class) {
            if (! is_subclass_of($class, PageLayout::class)) {
                throw new InvalidArgumentException("{$class} isn't a ".PageLayout::class.'.');
            }

            static::$registered[$class::handle()] = $class;
            static::schema($class::view(), fn (): array => $class::schema());
        }
    }

    /**
     * Register every layout class in a folder (and its subfolders) whose handle isn't taken
     * yet; abstract classes and other classes are left alone (Blocks::discover()'s twin).
     *
     * @return array<int, class-string<PageLayout>>
     */
    public static function discover(string $path, ?string $namespace = null): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $namespace = trim($namespace ?? Blocks::namespaceFor($path), '\\');
        $found = [];

        foreach ((new Finder)->files()->in($path)->name('*.php')->sortByName() as $file) {
            $class = $namespace.'\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! class_exists($class) || ! is_subclass_of($class, PageLayout::class) || ! (new ReflectionClass($class))->isInstantiable()) {
                continue;
            }

            if (! isset(static::$registered[$class::handle()])) {
                $found[] = $class;
            }
        }

        if ($found !== []) {
            static::register($found);
        }

        return $found;
    }

    /**
     * @return array<string, class-string<PageLayout>>
     */
    public static function registered(): array
    {
        return static::$registered;
    }

    /**
     * Stop offering a layout defined in code (a test).
     */
    public static function forget(string $handle): void
    {
        if (isset(static::$registered[$handle])) {
            static::forgetSchema(static::$registered[$handle]::view());
            unset(static::$registered[$handle]);
        }
    }

    /**
     * Whether the layout is defined in code that's still there.
     */
    public static function isRegistered(Layout $layout): bool
    {
        return $layout->isFromCode() && (static::$registered[$layout->handle] ?? null) === $layout->class;
    }

    /**
     * Bring the layouts defined in code into the table, from Layout::cached(): added the first
     * time, and changed when their class changes; nothing written otherwise. A layout added in
     * the admin keeps its handle: one in code with the same handle isn't offered.
     *
     * @param  Collection<int, Layout>  $rows  every layout, as read
     * @return bool whether any row was added or changed
     */
    public static function syncRegistered(Collection $rows): bool
    {
        if (static::$registered === [] || ! Layout::storesClasses()) {
            return false;
        }

        $changed = false;

        foreach (static::$registered as $handle => $class) {
            $row = $rows->firstWhere('handle', $handle);

            if ($row !== null && ! $row->isFromCode()) {
                continue;
            }

            $attributes = ['name' => $class::name(), 'description' => $class::description(), 'icon' => $class::icon(), 'view' => $class::view(), 'class' => $class];

            try {
                if ($row === null) {
                    Layout::query()->create([...$attributes, 'handle' => $handle, 'sort' => (int) $rows->max('sort') + 1, 'is_default' => $rows->isEmpty()]);
                    $changed = true;
                } elseif ($row->fill($attributes)->isDirty()) {
                    $row->save();
                    $changed = true;
                }
            } catch (UniqueConstraintViolationException) {
                // Another request added it at the same moment.
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Give pages drawn with this view fields of their own. Registering a view again replaces
     * its fields.
     *
     * @param  Closure(): array<int, Component>|array<int, Component>  $schema
     */
    public static function schema(string $view, Closure|array $schema): void
    {
        static::$schemas[$view] = $schema;
    }

    /**
     * Take a view's fields away again (a test).
     */
    public static function forgetSchema(string $view): void
    {
        unset(static::$schemas[$view]);
    }

    /**
     * The fields a layout's view asks for; none for a view that doesn't.
     *
     * @return array<int, Component>
     */
    public static function schemaFor(?Layout $layout): array
    {
        $schema = static::$schemas[$layout?->view ?? ''] ?? [];

        return $schema instanceof Closure ? $schema() : $schema;
    }

    /**
     * A layout's fields as an assistant reads them (list_layouts): name, type, label, options.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function describeSchema(?Layout $layout): array
    {
        return BlockCatalogue::fields(static::schemaFor($layout));
    }

    /**
     * Check values for a layout's fields, as the AI tools pass them: unknown fields and
     * values outside a field's options are refused, with messages an assistant can act on.
     *
     * @param  array<string, mixed>  $values
     * @return array<int, string>
     */
    public static function validateSettings(Layout $layout, array $values): array
    {
        return BlockCatalogue::checkFields($layout->handle, static::describeSchema($layout), $values);
    }

    /**
     * Where a view's fields are kept in a page's settings (settings.layouts.<key>): a dot
     * would read as nesting, so it becomes a slash, which names the same view to Laravel.
     */
    public static function settingsKey(string $view): string
    {
        return str_replace('.', '/', $view);
    }

    /**
     * Where a site's copies of Aurora's layout views go when published (on install, or with
     * vendor:publish --tag=aurora-layouts): Laravel's place for overriding a package's views.
     */
    public static function path(): string
    {
        return resource_path('views/vendor/aurora/layouts');
    }

    /**
     * The view that draws a page: its layout's, else the default layout's, else Aurora's
     * standard one (a layout whose view has gone draws as standard rather than failing).
     */
    public static function viewFor(Page $page): string
    {
        $view = $page->layoutOrDefault()?->view;

        return filled($view) && view()->exists($view) ? $view : 'aurora::layouts.standard';
    }

    /**
     * A layout drawn around a website route's own content, as around a page's blocks: the
     * site's head, header, menu and footer around an events list, say (<x-aurora::layout>).
     * The layout gets a stand-in page (Page::standIn()) with the title and SEO text given;
     * its content stands where the blocks would. The default layout, or the one with the
     * handle given.
     *
     * @param  array<string, mixed>  $settings  the layout's own fields, as a page's would be ($settings)
     */
    public static function wrap(Htmlable $content, string $title, ?string $description = null, ?string $layout = null, bool $noindex = false, array $settings = []): HtmlString
    {
        $chosen = (filled($layout) ? Layout::cached()->firstWhere('handle', $layout) : null) ?? Layout::default();
        $page = Page::standIn($title, $content, $description, $noindex, $chosen);
        $shared = array_intersect_key(View::getShared(), array_flip(['currentPage', 'previewing']));

        // What blocks and partials ask of the page being drawn (Blocks::page()), as on a page.
        View::share(['currentPage' => $page, 'previewing' => false]);

        try {
            return new HtmlString(view(static::viewFor($page), [
                'page' => $page,
                'layout' => $chosen,
                'settings' => $settings,
                'preview' => false,
                'embedded' => false,
            ])->render());
        } finally {
            View::share(['currentPage' => $shared['currentPage'] ?? null, 'previewing' => $shared['previewing'] ?? false]);
        }
    }

    /**
     * Add a layout ($layout null) or change one. The handle is set once, when it's added.
     *
     * @param  array{name: string, handle?: string, description?: ?string, icon?: ?string, view: string, is_default?: bool}  $data
     */
    public static function put(?Layout $layout, array $data): Layout
    {
        // A layout defined in code is its code's; it can only be made the default.
        if ($layout?->isFromCode()) {
            return ($data['is_default'] ?? false) ? static::makeDefault($layout) : $layout;
        }

        $attributes = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'view' => $data['view'],
        ];

        // The default stays one: another becomes it by being made the default.
        if (($data['is_default'] ?? false) || ! Layout::query()->where('is_default', true)->exists()) {
            $attributes['is_default'] = true;
        }

        if ($layout === null) {
            return Layout::query()->create([
                ...$attributes,
                'handle' => $data['handle'],
                'sort' => (int) Layout::query()->max('sort') + 1,
            ]);
        }

        $layout->update($attributes);

        return $layout;
    }

    /**
     * New pages, and pages without one of their own, get this layout; the one that was the
     * default stops being it.
     */
    public static function makeDefault(Layout $layout): Layout
    {
        $layout->update(['is_default' => true]);

        return $layout;
    }

    /**
     * Pages that used it go back to the default layout. The default itself stays: another
     * becomes the default first.
     */
    public static function remove(Layout $layout): bool
    {
        if ($layout->is_default || $layout->isFromCode()) {
            return false;
        }

        return (bool) $layout->delete();
    }
}
