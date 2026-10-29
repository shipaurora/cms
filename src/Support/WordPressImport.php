<?php

namespace Aurora\Support;

use Aurora\Enums\PageStatus;
use Aurora\Models\Menu;
use Aurora\Models\MenuItem;
use Aurora\Models\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SimpleXMLElement;
use Throwable;

/**
 * Moving a WordPress site in: its export file (Tools › Export, "All content") brings its
 * pages (and, if wanted, its posts, under a Blog page), its images into the media library,
 * and its menus. Each page's content becomes a rich text block, with links to its images
 * pointing at their new home. Settings › System › Import, or php artisan aurora:import-wordpress.
 */
class WordPressImport
{
    protected const WP = 'http://wordpress.org/export/1.2/';

    protected const CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    protected const EXCERPT = 'http://wordpress.org/export/1.2/excerpt/';

    /**
     * Old image addresses and their new ones.
     *
     * @var array<string, string>
     */
    protected array $images = [];

    /**
     * WordPress post ids and the pages they became.
     *
     * @var array<int, Page>
     */
    protected array $pages = [];

    /**
     * @param  bool  $withPosts  Bring blog posts in too, as pages under a Blog page.
     * @param  bool  $withMedia  Download the images into the media library.
     */
    public function __construct(protected bool $withPosts = true, protected bool $withMedia = true) {}

    /**
     * @return array{pages: int, posts: int, images: int, menus: int, skipped: array<int, string>}
     */
    public function import(string $path): array
    {
        $xml = @simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NOCDATA);

        if ($xml === false || ! isset($xml->channel)) {
            throw new InvalidArgumentException(__('That isn’t a WordPress export file (an .xml file from Tools › Export).'));
        }

        $items = collect(iterator_to_array($xml->channel->item, false));
        $result = ['pages' => 0, 'posts' => 0, 'images' => 0, 'menus' => 0, 'skipped' => []];

        if ($this->withMedia) {
            foreach ($items->filter(fn (SimpleXMLElement $item): bool => $this->wp($item, 'post_type') === 'attachment') as $item) {
                $this->importImage($item, $result);
            }
        }

        $blog = null;

        foreach ($items->filter(fn (SimpleXMLElement $item): bool => in_array($this->wp($item, 'post_type'), $this->withPosts ? ['page', 'post'] : ['page'], true)) as $item) {
            if (in_array($this->wp($item, 'status'), ['trash', 'auto-draft', 'inherit'], true)) {
                continue;
            }

            $isPost = $this->wp($item, 'post_type') === 'post';

            if ($isPost && $blog === null) {
                $blog = Page::query()->create(['title' => __('Blog'), 'status' => PageStatus::Published, 'blocks' => []]);
            }

            $page = Page::query()->create([
                'title' => (string) $item->title ?: __('Untitled'),
                'slug' => Page::uniqueSlug($this->wp($item, 'post_name') ?: (string) $item->title, parentId: $isPost ? $blog->getKey() : null),
                'excerpt' => Str::limit(trim(strip_tags((string) $item->children(self::EXCERPT)->encoded)), 290) ?: null,
                'status' => $this->wp($item, 'status') === 'publish' ? PageStatus::Published : PageStatus::Draft,
                'published_at' => $this->wp($item, 'status') === 'publish' ? $this->wp($item, 'post_date') : null,
                'parent_id' => $isPost ? $blog->getKey() : null,
                'blocks' => BlockTreeBuilder::flatten([$this->contentBlock((string) $item->children(self::CONTENT)->encoded)]),
            ]);

            $this->pages[(int) $this->wp($item, 'post_id')] = $page;
            $result[$isPost ? 'posts' : 'pages']++;
        }

        // Then where each page sits: its parent may have come after it in the file.
        foreach ($items->filter(fn (SimpleXMLElement $item): bool => $this->wp($item, 'post_type') === 'page') as $item) {
            $page = $this->pages[(int) $this->wp($item, 'post_id')] ?? null;
            $parent = $this->pages[(int) $this->wp($item, 'post_parent')] ?? null;

            if ($page !== null && $parent !== null) {
                $page->update(['parent_id' => $parent->getKey()]);
            }
        }

        $this->importMenus($items, $result);

        return $result;
    }

    /**
     * @param  array{pages: int, posts: int, images: int, menus: int, skipped: array<int, string>}  $result
     */
    protected function importImage(SimpleXMLElement $item, array &$result): void
    {
        $url = $this->wp($item, 'attachment_url');

        if (! preg_match('/\.(jpe?g|png|gif|webp|avif|svg)$/i', (string) parse_url($url, PHP_URL_PATH))) {
            return;
        }

        try {
            $response = Http::timeout(20)->get($url);

            if (! $response->successful()) {
                throw new InvalidArgumentException('HTTP '.$response->status());
            }

            $file = tempnam(sys_get_temp_dir(), 'wp').'-'.basename((string) parse_url($url, PHP_URL_PATH));
            file_put_contents($file, $response->body());
            $asset = AssetLibrary::fromFile($file, ['title' => (string) $item->title ?: basename($file)]);
            @unlink($file);

            $this->images[$url] = $asset->url(null);
            $result['images']++;
        } catch (Throwable $exception) {
            $result['skipped'][] = $url.': '.$exception->getMessage();
        }
    }

    /**
     * A page's WordPress content as one rich text block, its images at their new addresses
     * (WordPress's resized copies of an image, too) and the block editor's markers gone.
     *
     * @return array<string, mixed>
     */
    protected function contentBlock(string $html): array
    {
        $html = preg_replace('/<!--\s*\/?wp:[^>]*-->/', '', $html) ?? $html;

        foreach ($this->images as $old => $new) {
            $base = preg_quote(preg_replace('/\.[a-z0-9]+$/i', '', $old) ?? $old, '/');
            $html = preg_replace('/'.$base.'(-\d+x\d+)?\.[a-z0-9]+/i', $new, $html) ?? $html;
        }

        // Paragraphs where WordPress left only line breaks (its classic editor).
        if (! str_contains($html, '<p')) {
            $html = collect(preg_split('/\n\s*\n/', trim($html)))->filter()->map(fn (string $paragraph): string => '<p>'.nl2br(trim($paragraph)).'</p>')->implode('');
        }

        return ['type' => 'rich-text', 'data' => ['body' => trim($html), 'width' => 'prose']];
    }

    /**
     * @param  Collection<int, SimpleXMLElement>  $items
     * @param  array{pages: int, posts: int, images: int, menus: int, skipped: array<int, string>}  $result
     */
    protected function importMenus($items, array &$result): void
    {
        $menuItems = $items->filter(fn (SimpleXMLElement $item): bool => $this->wp($item, 'post_type') === 'nav_menu_item');
        $menus = [];
        $made = [];

        foreach ($menuItems->sortBy(fn (SimpleXMLElement $item): int => (int) $this->wp($item, 'menu_order')) as $item) {
            $term = collect(iterator_to_array($item->category, false))->first(fn (SimpleXMLElement $category): bool => (string) $category['domain'] === 'nav_menu');

            if ($term === null) {
                continue;
            }

            $menu = $menus[(string) $term['nicename']] ??= tap(Menu::query()->create(['name' => (string) $term, 'is_visible' => true]), function () use (&$result): void {
                $result['menus']++;
            });

            $meta = $this->meta($item);
            $page = $meta['_menu_item_type'] === 'post_type' ? ($this->pages[(int) $meta['_menu_item_object_id']] ?? null) : null;

            $menuItem = new MenuItem;
            $menuItem->forceFill([
                'menu_id' => $menu->getKey(),
                'title' => (string) $item->title ?: $page?->title ?? __('Link'),
                'url' => $page === null ? ($meta['_menu_item_url'] ?: '#') : null,
                'linkable_type' => $page?->getMorphClass(),
                'linkable_id' => $page?->getKey(),
                'parent_id' => $made[(int) $meta['_menu_item_menu_item_parent']] ?? null,
                'order' => (int) $this->wp($item, 'menu_order'),
            ])->save();

            $made[(int) $this->wp($item, 'post_id')] = $menuItem->getKey();
        }
    }

    protected function wp(SimpleXMLElement $item, string $field): string
    {
        return trim((string) $item->children(self::WP)->{$field});
    }

    /**
     * @return array<string, string>
     */
    protected function meta(SimpleXMLElement $item): array
    {
        return collect(iterator_to_array($item->children(self::WP)->postmeta, false))
            ->mapWithKeys(fn (SimpleXMLElement $meta): array => [(string) $meta->children(self::WP)->meta_key => (string) $meta->children(self::WP)->meta_value])
            ->all() + ['_menu_item_type' => '', '_menu_item_object_id' => '', '_menu_item_url' => '', '_menu_item_menu_item_parent' => ''];
    }
}
