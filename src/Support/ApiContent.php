<?php

namespace Aurora\Support;

use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Filament\Forms\Components\PagePicker;
use Aurora\Models\Asset;
use Aurora\Models\Form;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use CarlJanzell\FilamentPageBuilder\BlockRegistries;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Content as the website's APIs give it (Aurora\Support\PublicApi): structured, so an app can
 * draw it without knowing the CMS. What a block or setting stores as an id comes out as what
 * it points at, worked out from its fields: a file (its addresses, sizes and alt text), a
 * page (its title and address, only if anyone may see it), a form (its fields). Rich text comes out as the website's cleaned HTML.
 *
 * Only what visitors may see: never a page behind a password or sign-in, a form's
 * notification addresses, a file's place in the library or who uploaded it, or a setting
 * no tab lets the APIs show.
 */
class ApiContent
{
    /**
     * The site's own settings anyone may read; tabs add theirs (SettingsTabs, publicKeys).
     *
     * @var array<int, string>
     */
    public const SETTINGS = ['site_name', 'tagline', 'brand_color', 'accent_color', 'contact_email'];

    /**
     * What each block type's fields hold, by type.
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $blockKinds = [];

    /**
     * @param  array<int, array<string, mixed>>  $blocks  Nested (BlockTreeBuilder::nest()).
     * @return array<int, array<string, mixed>>
     */
    public static function blocks(array $blocks): array
    {
        return array_values(array_map(fn (mixed $block): mixed => is_array($block) ? static::block($block) : $block, $blocks));
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public static function block(array $block): array
    {
        $type = (string) ($block['type'] ?? '');
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        $block['data'] = static::resolve($data, static::blockKinds($type));

        if (is_array($block['children'] ?? null)) {
            $block['children'] = array_is_list($block['children'])
                ? static::blocks($block['children'])
                : array_map(fn (mixed $slot): mixed => is_array($slot) ? static::blocks($slot) : $slot, $block['children']);
        }

        return $block;
    }

    /**
     * The website's settings anyone may read, structured like blocks.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        $keys = PublicApi::publicSettings();
        $kinds = array_filter(
            static::kinds(SettingsTabs::publicTabs()),
            fn (string $name): bool => in_array(Str::before($name, '.'), $keys, true),
            ARRAY_FILTER_USE_KEY,
        );

        return static::resolve(Arr::only(Setting::forCurrentSite(), $keys), $kinds);
    }

    /**
     * A file, with everything an app needs to show it, and nothing about where it's kept.
     *
     * @return array<string, mixed>
     */
    public static function asset(Asset $asset): array
    {
        $file = $asset->file();

        return [
            'id' => $asset->id,
            'type' => $asset->type()->value,
            'mime_type' => $asset->mime_type,
            'title' => $asset->title,
            'alt' => $asset->alt,
            'caption' => $asset->caption,
            'credit' => $asset->credit,
            'url' => $asset->url(null),
            ...$asset->dimensions(),
            'size' => $file?->size,
            'focal_point' => $asset->focal_point,
            'sizes' => $file === null ? [] : collect($file->generated_conversions ?? [])
                ->filter()
                ->keys()
                ->mapWithKeys(fn (string $size): array => [$size => $file->getUrl($size)])
                ->all(),
            'srcset' => $asset->srcset(),
        ];
    }

    /**
     * A file an image or file field holds (an id, or an id with this block's crop and edits:
     * then its addresses are the edited image's).
     *
     * @return array<string, mixed>|null
     */
    public static function fieldAsset(mixed $value): ?array
    {
        $asset = AssetLibrary::find($value);

        if ($asset === null) {
            return null;
        }

        $resolved = static::asset($asset);

        if ($asset->isImage() && ! ImageEdits::from($value)->isEmpty()) {
            $resolved = [...$resolved, 'url' => AssetLibrary::url($value, null), 'sizes' => [], 'srcset' => AssetLibrary::srcset($value), 'edited' => true];
        }

        return $resolved;
    }

    /**
     * A page a field links to, when anyone may see it (looked up once per request).
     *
     * @return array{id: string, title: string, path: string, url: string}|null
     */
    public static function page(mixed $id): ?array
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return once(function () use ($id): ?array {
            $page = Page::query()->public()->find($id, ['id', 'site_id', 'parent_id', 'title', 'slug', 'is_homepage', 'locales']);

            return $page === null ? null : ['id' => $page->id, 'title' => $page->title, 'path' => $page->path(), 'url' => $page->url()];
        });
    }

    /**
     * A form a block shows: its fields, to draw it. Not who is told about answers.
     *
     * @return array<string, mixed>|null
     */
    public static function form(mixed $id): ?array
    {
        $form = is_string($id) && Str::isUuid($id) ? Form::query()->where('is_active', true)->find($id) : null;

        if ($form === null) {
            return null;
        }

        return [
            'id' => $form->id,
            'submit_label' => $form->submit_label,
            'success_message' => $form->success_message,
            'redirect' => static::page($form->redirect_page_id),
            'fields' => array_map(fn (array $field): array => array_filter([
                'type' => $field['type'],
                'name' => $field['name'],
                'label' => $field['label'],
                'required' => $field['required'],
                ...Arr::only($field['data'], ['placeholder', 'help', 'options', 'width', 'accept', 'max_mb', 'text']),
                'show_if' => filled($field['data']['show_if_field'] ?? null)
                    ? ['field' => $field['data']['show_if_field'], 'value' => $field['data']['show_if_value'] ?? null]
                    : null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''), $form->definitions()),
        ];
    }

    /**
     * A few words of a page around where a search matched (the text the search index holds),
     * or the start of it when no word is found as written.
     */
    public static function snippet(Page $page, string $terms, int $length = 200): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', PageSearch::textIn($page, ContentLocale::current())) ?? '');

        if ($text === '') {
            return null;
        }

        $words = array_filter(preg_split('/\s+/u', trim($terms)) ?: [], fn (string $word): bool => mb_strlen($word) > 1);
        $at = collect($words)->map(fn (string $word): int|false => mb_stripos($text, $word))->filter(fn (int|false $at): bool => $at !== false)->min() ?? 0;
        $start = max(0, $at - intdiv($length, 3));

        return ($start > 0 ? '…' : '').trim(mb_substr($text, $start, $length)).($start + $length < mb_strlen($text) ? '…' : '');
    }

    /**
     * The files content anyone may see uses: on live public pages and in the settings the
     * APIs show. Other files in the library aren't
     * listed or shown. Worked out once until the content changes (PageCache::remember()).
     *
     * @return array<int, string>
     */
    public static function publicAssetIds(): array
    {
        return once(fn (): array => PageCache::remember('api-assets.'.CurrentSite::id(), function (): array {
            $pages = Page::query()->public()->get(['id', 'blocks', 'block_translations', 'og_image']);
            $content = $pages->map(fn (Page $page): array => [$page->blocks, $page->block_translations, $page->og_image])->all();

            return AssetLibrary::referencedIds([$content, Arr::only(Setting::forCurrentSite(), PublicApi::publicSettings())]);
        }));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $kinds
     * @return array<string, mixed>
     */
    protected static function resolve(array $data, array $kinds): array
    {
        foreach ($kinds as $name => $kind) {
            if (Arr::has($data, $name)) {
                Arr::set($data, $name, static::value(Arr::get($data, $name), $kind));
            }
        }

        return $data;
    }

    protected static function value(mixed $value, mixed $kind): mixed
    {
        return match (true) {
            $kind === 'asset' => static::fieldAsset(is_array($value) && array_is_list($value) ? Arr::first($value) : $value),
            $kind === 'assets' => array_values(array_filter(array_map(fn (mixed $item): ?array => static::fieldAsset($item), (array) $value))),
            $kind === 'page' => static::page($value),
            $kind === 'pages' => array_values(array_filter(array_map(fn (mixed $id): ?array => static::page($id), (array) $value))),
            $kind === 'form' => static::form($value),
            $kind === 'html' => is_string($value) ? (string) Markup::rich($value) : $value,
            is_array($kind) && isset($kind['upload']) => static::upload($value, $kind['upload']),
            is_array($kind) && isset($kind['list']) => is_array($value)
                ? array_values(array_map(fn (mixed $item): mixed => is_array($item) ? static::resolve($item, $kind['list']) : $item, $value))
                : $value,
            default => $value,
        };
    }

    protected static function upload(mixed $value, string $disk): mixed
    {
        $url = fn (mixed $path): ?string => is_string($path) && $path !== '' ? (Str::startsWith($path, ['http://', 'https://']) ? $path : Storage::disk($disk)->url($path)) : null;

        return is_array($value) ? array_values(array_filter(array_map($url, $value))) : $url($value);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function blockKinds(string $type): array
    {
        if (! array_key_exists($type, static::$blockKinds)) {
            $class = app(BlockRegistries::class)->for('admin')->find($type);

            static::$blockKinds[$type] = $class === null ? [] : static::kinds(rescue(fn (): array => $class::schema(), [], report: false));
        }

        return static::$blockKinds[$type];
    }

    /**
     * What each field in these components holds, by its name: the kinds that need working out.
     *
     * @param  array<int, mixed>  $components
     * @return array<string, mixed>
     */
    protected static function kinds(array $components): array
    {
        try {
            $components = Schema::make()->components($components)->getComponents(withHidden: true);
        } catch (Throwable) {
            return [];
        }

        $kinds = [];

        foreach ($components as $component) {
            if ($component instanceof Field) {
                if (($kind = static::kind($component)) !== null) {
                    $kinds[$component->getName()] = $kind;
                }

                continue;
            }

            if ($component instanceof Component) {
                $kinds += rescue(fn (): array => static::kinds($component->getDefaultChildComponents()), [], report: false);
            }
        }

        return $kinds;
    }

    protected static function kind(Field $field): mixed
    {
        $name = $field->getName();

        return match (true) {
            $field instanceof AssetPicker => rescue(fn (): bool => $field->isMultiple(), false, report: false) ? 'assets' : 'asset',
            $field instanceof PagePicker, str_ends_with($name, '_page') => 'page',
            $name === 'page_ids' => 'pages',
            $name === 'form_id' => 'form',
            $field instanceof RichEditor => 'html',
            $field instanceof FileUpload => ['upload' => rescue(fn (): string => $field->getDiskName(), 'public', report: false)],
            $field instanceof Repeater => ['list' => rescue(fn (): array => static::kinds($field->getDefaultChildComponents()), [], report: false)],
            default => null,
        };
    }
}
