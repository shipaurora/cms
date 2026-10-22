<?php

namespace Aurora\Support;

use Aurora\Models\Layout;
use Aurora\Models\Page;
use Closure;
use Filament\Schemas\Components\Component;

/**
 * Page layouts and the views that draw them. A layout names any Blade view: Aurora's own
 * (aurora::layouts.standard and .blank, published to the site on install to make them its
 * own) or one of the site's. Each gets $page, $layout, $settings, $preview and $embedded.
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
     * Add a layout ($layout null) or change one. The handle is set once, when it's added.
     *
     * @param  array{name: string, handle?: string, description?: ?string, icon?: ?string, view: string, is_default?: bool}  $data
     */
    public static function put(?Layout $layout, array $data): Layout
    {
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
     * Pages that used it go back to the default layout. The default itself stays: another
     * becomes the default first.
     */
    public static function remove(Layout $layout): bool
    {
        if ($layout->is_default) {
            return false;
        }

        return (bool) $layout->delete();
    }
}
