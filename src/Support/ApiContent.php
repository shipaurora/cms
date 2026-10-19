<?php

namespace Aurora\Support;

use Aurora\Blocks\SharedSectionBlock;
use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Filament\Forms\Components\PagePicker;
use Aurora\Models\Asset;
use Aurora\Models\Form;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\SharedSection;
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
 * page (its title and address, only if anyone may see it), a form (its fields), a shared
 * section (its blocks). Rich text comes out as the website's cleaned HTML.
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
     * Shared sections being worked out, so one inside itself stops.
     *
     * @var array<int, string>
     */
    protected static array $sections = [];

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

        if ($type === SharedSectionBlock::type()) {
            $block['data']['shared_section'] = static::sharedSection($data['shared_section_id'] ?? null);
        }

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
     * A page a field links to, when anyone may see it.
     *
     * @return array{id: string, title: string, path: string, url: string}|null
     */
    public static function page(mixed $id): ?array
    {
        return is_string($id) ? (static::pages()[$id] ?? null) : null;
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
     * The files content anyone may see uses: on live public pages (and the shared sections
     * they place), and in the settings the APIs show. Other files in the library aren't
     * listed or shown.
     *
     * @return array<int, string>
     */
    public static function publicAssetIds(): array
    {
        return once(function (): array {
            $pages = Page::query()->public()->get(['id', 'blocks', 'block_translations', 'og_image']);
            $content = $pages->map(fn (Page $page): array => [$page->blocks, $page->block_translations, $page->og_image])->all();

            $sections = SharedSection::query()
                ->whereIn('id', static::uuids($content))
                ->get(['blocks', 'block_translations'])
                ->map(fn (SharedSection $section): array => [$section->blocks, $section->block_translations])
                ->all();

            return AssetLibrary::referencedIds([$content, $sections, Arr::only(Setting::forCurrentSite(), PublicApi::publicSettings())]);
        });
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
     * @return array{id: string, name: string, blocks: array<int, array<string, mixed>>}|null
     */
    protected static function sharedSection(mixed $id): ?array
    {
        $section = is_string($id) && Str::isUuid($id) ? SharedSection::query()->find($id) : null;

        if ($section === null || in_array($section->id, static::$sections, true)) {
            return null;
        }

        static::$sections[] = $section->id;

        try {
            return ['id' => $section->id, 'name' => $section->name, 'blocks' => static::blocks(BlockTreeBuilder::nest($section->localizedBlocks()))];
        } finally {
            array_pop(static::$sections);
        }
    }

    /**
     * Every live page anyone may see, by id, in the language asked for.
     *
     * @return array<string, array{id: string, title: string, path: string, url: string}>
     */
    protected static function pages(): array
    {
        return once(fn (): array => Page::query()->public()->get()->mapWithKeys(fn (Page $page): array => [$page->id => [
            'id' => $page->id,
            'title' => $page->title,
            'path' => $page->path(),
            'url' => $page->url(),
        ]])->all());
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

    /**
     * @return array<int, string>
     */
    protected static function uuids(mixed $value): array
    {
        if (is_string($value)) {
            return Str::isUuid($value) ? [$value] : [];
        }

        return is_array($value) ? array_values(array_unique(array_merge([], ...array_map(fn (mixed $child): array => static::uuids($child), array_values($value))))) : [];
    }
}
