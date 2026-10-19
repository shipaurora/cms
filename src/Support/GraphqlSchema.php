<?php

namespace Aurora\Support;

use Aurora\Enums\ApiScope;
use Aurora\Enums\AssetType;
use Aurora\Models\Asset;
use Aurora\Models\Menu;
use Aurora\Models\MenuItem;
use Aurora\Models\Page;
use Closure;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Illuminate\Http\Request;

/**
 * The GraphQL API's schema (Aurora\Support\PublicApi): the same pages, menus and settings
 * as the REST API, read-only, with fields in GraphQL's camelCase. A field is only worked out
 * when a query asks for it, so a list of titles never builds the pages' blocks.
 */
class GraphqlSchema
{
    /**
     * Pages a query may ask for at once.
     */
    public const MAX_PER_PAGE = 100;

    protected static ?Schema $schema = null;

    public static function get(): Schema
    {
        return static::$schema ??= static::build();
    }

    /**
     * A query the request's API key needs a scope for (Aurora\Enums\ApiScope): the request
     * is the query's context (GraphqlController).
     */
    protected static function scoped(ApiScope $scope, Closure $resolve): Closure
    {
        return function (mixed $root, array $args, mixed $request) use ($scope, $resolve): mixed {
            if ($request instanceof Request && ! PublicApi::permits($request, $scope)) {
                throw new UserError("This API key can't do this: it needs the \"{$scope->value}\" scope.");
            }

            return $resolve($root, $args);
        };
    }

    protected static function build(): Schema
    {
        $json = new CustomScalarType([
            'name' => 'JSON',
            'description' => 'Any JSON value: a page\'s blocks, the website\'s settings.',
            'serialize' => fn (mixed $value): mixed => $value,
        ]);

        // A file, as ApiContent::asset() gives it.
        $media = new ObjectType([
            'name' => 'Media',
            'description' => 'A file the website\'s visible content uses.',
            'fields' => [
                'id' => ['type' => Type::nonNull(Type::id())],
                'type' => ['type' => Type::nonNull(Type::string()), 'description' => 'image, video, audio, pdf, document …'],
                'mimeType' => ['type' => Type::string(), 'resolve' => fn (array $asset): ?string => $asset['mime_type']],
                'title' => ['type' => Type::string()],
                'alt' => ['type' => Type::string()],
                'caption' => ['type' => Type::string()],
                'credit' => ['type' => Type::string()],
                'url' => ['type' => Type::string(), 'description' => 'The original, or the edited image when a block crops it.'],
                'width' => ['type' => Type::int()],
                'height' => ['type' => Type::int()],
                'size' => ['type' => Type::int(), 'description' => 'In bytes.'],
                'focalPoint' => ['type' => $json, 'resolve' => fn (array $asset): ?array => $asset['focal_point']],
                'sizes' => ['type' => Type::nonNull($json), 'description' => 'Smaller copies by name (thumb, sm, md …): their addresses.'],
                'srcset' => ['type' => Type::string()],
            ],
        ]);

        $mediaList = new ObjectType([
            'name' => 'MediaList',
            'fields' => [
                'items' => ['type' => Type::nonNull(Type::listOf(Type::nonNull($media)))],
                'total' => ['type' => Type::nonNull(Type::int())],
                'currentPage' => ['type' => Type::nonNull(Type::int())],
                'lastPage' => ['type' => Type::nonNull(Type::int())],
            ],
        ]);

        $seo = new ObjectType([
            'name' => 'Seo',
            'fields' => [
                'title' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn (Page $page): string => $page->seoTitle()],
                'description' => ['type' => Type::string(), 'resolve' => fn (Page $page): ?string => $page->meta_description ?: $page->excerpt],
                'image' => ['type' => $media, 'resolve' => fn (Page $page): ?array => ApiContent::fieldAsset($page->og_image)],
                'noindex' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn (Page $page): bool => (bool) $page->noindex],
            ],
        ]);

        $page = new ObjectType([
            'name' => 'Page',
            'description' => 'A live page anyone may see, in the language asked for (?locale=).',
            'fields' => [
                'id' => ['type' => Type::nonNull(Type::id())],
                'title' => ['type' => Type::nonNull(Type::string())],
                'slug' => ['type' => Type::nonNull(Type::string())],
                'path' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn (Page $page): string => $page->path()],
                'url' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn (Page $page): string => $page->url()],
                'excerpt' => ['type' => Type::string()],
                'parentId' => ['type' => Type::id(), 'resolve' => fn (Page $page): ?string => $page->parent_id],
                'isHomepage' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn (Page $page): bool => (bool) $page->is_homepage],
                'publishedAt' => ['type' => Type::string(), 'description' => 'ISO 8601.', 'resolve' => fn (Page $page): ?string => $page->published_at?->toIso8601String()],
                'updatedAt' => ['type' => Type::string(), 'description' => 'ISO 8601.', 'resolve' => fn (Page $page): ?string => $page->updated_at?->toIso8601String()],
                'layout' => ['type' => Type::string(), 'description' => 'The layout\'s handle.', 'resolve' => fn (Page $page): ?string => $page->layoutOrDefault()?->handle],
                'locales' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))), 'resolve' => fn (Page $page): array => $page->availableLocales()],
                'seo' => ['type' => Type::nonNull($seo), 'resolve' => fn (Page $page): Page => $page],
                'blocks' => ['type' => Type::nonNull($json), 'description' => 'The page\'s blocks, nested: each with its type, data and children. Ids in the data come as what they point at: a file, a page, a form.', 'resolve' => fn (Page $page): array => ApiContent::blocks(BlockTreeBuilder::nest($page->localizedBlocks()))],
            ],
        ]);

        $pageList = new ObjectType([
            'name' => 'PageList',
            'fields' => [
                'items' => ['type' => Type::nonNull(Type::listOf(Type::nonNull($page)))],
                'total' => ['type' => Type::nonNull(Type::int())],
                'currentPage' => ['type' => Type::nonNull(Type::int())],
                'lastPage' => ['type' => Type::nonNull(Type::int())],
            ],
        ]);

        // Its children are menu items too: the fields are read once the type exists.
        $searchResult = new ObjectType([
            'name' => 'SearchResult',
            'fields' => [
                'page' => ['type' => Type::nonNull($page)],
                'snippet' => ['type' => Type::string(), 'description' => 'A few words of the page around where it matched.'],
                'score' => ['type' => Type::float(), 'description' => 'How well it matched (higher is better), when the search says.'],
            ],
        ]);

        $searchResults = new ObjectType([
            'name' => 'SearchResults',
            'fields' => [
                'items' => ['type' => Type::nonNull(Type::listOf(Type::nonNull($searchResult)))],
                'total' => ['type' => Type::nonNull(Type::int())],
                'currentPage' => ['type' => Type::nonNull(Type::int())],
                'lastPage' => ['type' => Type::nonNull(Type::int())],
            ],
        ]);

        $menuItem = new ObjectType([
            'name' => 'MenuItem',
            'fields' => function () use (&$menuItem): array {
                return [
                    'title' => ['type' => Type::nonNull(Type::string())],
                    'url' => ['type' => Type::string()],
                    'target' => ['type' => Type::string(), 'resolve' => fn (MenuItem $item): ?string => $item->target?->value ?? $item->target],
                    'children' => ['type' => Type::nonNull(Type::listOf(Type::nonNull($menuItem))), 'resolve' => fn (MenuItem $item): array => $item->children->all()],
                ];
            },
        ]);

        $menu = new ObjectType([
            'name' => 'Menu',
            'fields' => [
                'name' => ['type' => Type::nonNull(Type::string())],
                'location' => ['type' => Type::nonNull(Type::string())],
                'items' => ['type' => Type::nonNull(Type::listOf(Type::nonNull($menuItem))), 'resolve' => fn (array $menu): array => $menu['model']->menuItems->all()],
            ],
        ]);

        return new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'pages' => [
                        'type' => Type::nonNull($pageList),
                        'description' => 'The live pages, newest change first.',
                        'args' => [
                            'page' => ['type' => Type::int(), 'defaultValue' => 1],
                            'perPage' => ['type' => Type::int(), 'defaultValue' => 50, 'description' => 'At most '.self::MAX_PER_PAGE.'.'],
                            'parent' => ['type' => Type::id(), 'description' => 'Only the pages under this one; "" for the top level.'],
                        ],
                        'resolve' => static::scoped(ApiScope::PagesList, function (mixed $root, array $args): array {
                            $pages = Page::query()->public()->when(array_key_exists('parent', $args), fn ($query) => $query->where('parent_id', $args['parent'] ?: null))->latest('updated_at')->paginate(
                                perPage: max(1, min(self::MAX_PER_PAGE, $args['perPage'])),
                                page: max(1, $args['page']),
                            );

                            return ['items' => $pages->items(), 'total' => $pages->total(), 'currentPage' => $pages->currentPage(), 'lastPage' => $pages->lastPage()];
                        }),
                    ],
                    'page' => [
                        'type' => $page,
                        'description' => 'One page, by its id or by its address on the website ("/" is the homepage).',
                        'args' => [
                            'id' => ['type' => Type::id()],
                            'path' => ['type' => Type::string()],
                        ],
                        'resolve' => static::scoped(ApiScope::PagesRead, fn (mixed $root, array $args): ?Page => match (true) {
                            isset($args['id']) => Page::query()->public()->find($args['id']),
                            isset($args['path']) => PublicApi::pageAt($args['path']),
                            default => null,
                        }),
                    ],
                    'search' => [
                        'type' => Type::nonNull($searchResults),
                        'description' => 'The pages a search finds, as the website\'s own search finds them, most relevant first.',
                        'args' => [
                            'query' => ['type' => Type::nonNull(Type::string())],
                            'page' => ['type' => Type::int(), 'defaultValue' => 1],
                            'perPage' => ['type' => Type::int(), 'defaultValue' => 20, 'description' => 'At most '.self::MAX_PER_PAGE.'.'],
                        ],
                        'resolve' => static::scoped(ApiScope::Search, function (mixed $root, array $args): array {
                            $terms = trim($args['query']);

                            if (mb_strlen($terms) < 2) {
                                throw new UserError('Search for at least 2 characters.');
                            }

                            $pages = Page::search(mb_substr($terms, 0, 200))->paginate(max(1, min(self::MAX_PER_PAGE, $args['perPage'])), 'page', max(1, $args['page']));
                            $scores = PageSearch::scores(array_map(fn (Page $page): string => $page->getKey(), $pages->items()), $terms);

                            return [
                                'items' => array_map(fn (Page $page): array => ['page' => $page, 'snippet' => ApiContent::snippet($page, $terms), 'score' => $scores[$page->id] ?? null], $pages->items()),
                                'total' => $pages->total(),
                                'currentPage' => $pages->currentPage(),
                                'lastPage' => $pages->lastPage(),
                            ];
                        }),
                    ],
                    'menu' => [
                        'type' => $menu,
                        'description' => 'The menu in a menu location, such as header.',
                        'args' => ['location' => ['type' => Type::nonNull(Type::string())]],
                        'resolve' => static::scoped(ApiScope::MenusRead, fn (mixed $root, array $args): ?array => ($model = Menu::location($args['location'])) === null
                            ? null
                            : ['name' => $model->name, 'location' => $args['location'], 'model' => $model]),
                    ],
                    'settings' => [
                        'type' => Type::nonNull($json),
                        'description' => 'The website\'s settings anyone may see: its name, tagline, colours and the settings its blocks and layouts make public.',
                        'resolve' => static::scoped(ApiScope::SettingsRead, fn (): array => ApiContent::settings()),
                    ],
                    'media' => [
                        'type' => $media,
                        'description' => 'One file the website\'s visible content uses.',
                        'args' => ['id' => ['type' => Type::nonNull(Type::id())]],
                        'resolve' => static::scoped(ApiScope::MediaRead, fn (mixed $root, array $args): ?array => in_array($args['id'], ApiContent::publicAssetIds(), true)
                            ? ApiContent::asset(Asset::query()->findOrFail($args['id']))
                            : null),
                    ],
                    'mediaList' => [
                        'type' => Type::nonNull($mediaList),
                        'description' => 'The files the website\'s visible content uses, newest first.',
                        'args' => [
                            'page' => ['type' => Type::int(), 'defaultValue' => 1],
                            'perPage' => ['type' => Type::int(), 'defaultValue' => 50, 'description' => 'At most '.self::MAX_PER_PAGE.'.'],
                            'type' => ['type' => Type::string(), 'description' => 'One kind: image, video, audio, pdf, document …'],
                        ],
                        'resolve' => static::scoped(ApiScope::MediaRead, function (mixed $root, array $args): array {
                            $assets = Asset::query()
                                ->whereIn('id', ApiContent::publicAssetIds())
                                ->when(AssetType::tryFrom((string) ($args['type'] ?? '')), fn ($query, AssetType $type) => $type->scope($query))
                                ->latest()
                                ->paginate(perPage: max(1, min(self::MAX_PER_PAGE, $args['perPage'])), page: max(1, $args['page']));

                            return ['items' => array_map(fn (Asset $asset): array => ApiContent::asset($asset), $assets->items()), 'total' => $assets->total(), 'currentPage' => $assets->currentPage(), 'lastPage' => $assets->lastPage()];
                        }),
                    ],
                ],
            ]),
        ]);
    }
}
