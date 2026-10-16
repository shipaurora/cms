<?php

namespace Aurora\Database\Seeders\Demo;

use Aurora\Models\Menu;
use Aurora\Models\MenuItem;
use Aurora\Models\Page;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $pages = Page::query()->get()->mapWithKeys(fn (Page $page): array => [$page->slug => $page->id]);

        $this->menu('Main navigation', 'header', [
            ['page' => 'features', 'children' => [
                ['title' => ['en' => 'All features', 'nb' => 'Alle funksjoner'], 'page' => 'features', 'icon' => 'hero-o-squares-2x2'],
                ['page' => 'why-aurora', 'icon' => 'hero-o-light-bulb'],
                ['page' => 'architecture', 'icon' => 'hero-o-cube'],
                ['page' => 'components', 'icon' => 'hero-o-swatch'],
            ]],
            ['page' => 'docs', 'children' => [
                ['page' => 'getting-started', 'icon' => 'hero-o-rocket-launch'],
                ['page' => 'creating-blocks', 'icon' => 'hero-o-code-bracket'],
                ['page' => 'website-settings', 'icon' => 'hero-o-adjustments-horizontal'],
                ['page' => 'translations', 'icon' => 'hero-o-language'],
                ['page' => 'ai-and-mcp', 'icon' => 'hero-o-sparkles'],
                ['page' => 'security', 'icon' => 'hero-o-shield-check'],
            ]],
            ['page' => 'guides'],
            ['page' => 'release-notes'],
        ], $pages);

        $this->menu('Footer', 'footer', [
            ['title' => ['en' => 'Product', 'nb' => 'Produkt'], 'children' => [['page' => 'features'], ['page' => 'why-aurora'], ['page' => 'architecture'], ['page' => 'release-notes']]],
            ['title' => ['en' => 'Learn', 'nb' => 'Lær'], 'children' => [['page' => 'docs'], ['page' => 'getting-started'], ['page' => 'guides'], ['page' => 'components']]],
            ['title' => ['en' => 'Elsewhere', 'nb' => 'Andre steder'], 'children' => [['page' => 'contact'], ['title' => ['en' => 'GitHub', 'nb' => 'GitHub'], 'url' => 'https://github.com', 'target' => '_blank'], ['title' => ['en' => 'Admin', 'nb' => 'Admin'], 'url' => '/admin']]],
        ], $pages);

        $this->menu('Legal', 'legal', [
            ['page' => 'privacy'],
            ['page' => 'terms'],
            ['title' => ['en' => 'Sitemap', 'nb' => 'Nettstedskart'], 'url' => '/sitemap.xml'],
        ], $pages);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function menu(string $name, string $location, array $items, $pages): void
    {
        $menu = Menu::query()->create(['name' => $name, 'is_visible' => true]);
        $menu->locations()->create(['location' => $location]);

        $this->items($menu, $items, null, $pages);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function items(Menu $menu, array $items, ?string $parentId, $pages): void
    {
        foreach ($items as $order => $item) {
            $page = isset($item['page']) ? Page::query()->find($pages[$item['page']]) : null;

            $model = MenuItem::query()->create([
                'menu_id' => $menu->id,
                'parent_id' => $parentId,
                // Its own wording in English and Norwegian; a page's item has none, and shows the
                // page's title in each language (Aurora\Models\MenuItem).
                'title' => $item['title'] ?? [],
                'url' => $page ? null : ($item['url'] ?? null),
                'linkable_type' => $page?->getMorphClass(),
                'linkable_id' => $page?->getKey(),
                'icon' => $item['icon'] ?? null,
                'target' => $item['target'] ?? '_self',
                'order' => $order + 1,
            ]);

            if (! empty($item['children'])) {
                $this->items($menu, $item['children'], $model->id, $pages);
            }
        }
    }
}
