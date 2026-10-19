<?php

namespace Aurora\Support;

use Aurora\Models\Layout;
use Aurora\Models\Page;

/**
 * Page layouts and the views that draw them. A layout names any Blade view: Aurora's own
 * (aurora::layouts.standard and .blank, published to the site on install to make them its
 * own) or one of the site's. Each gets $page, $layout, $preview and $embedded.
 */
class Layouts
{
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
