<?php

namespace Aurora\Support;

use Aurora\Blocks\Block;
use Aurora\Blocks\Fields;
use Aurora\Enums\AssetType;
use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Models\Asset;
use CarlJanzell\FilamentPageBuilder\BlockRegistries;
use CarlJanzell\FilamentPageBuilder\BlockRegistry;
use CarlJanzell\FilamentPageBuilder\Contracts\Container;
use CarlJanzell\FilamentPageBuilder\Contracts\InlineEditable;
use CarlJanzell\FilamentPageBuilder\FilamentPageBuilderPlugin;
use CarlJanzell\FilamentPageBuilder\Support\BlockStyle;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Throwable;
use Wallacemartinss\FilamentIconPicker\Forms\Components\IconPickerField;

/**
 * Everything an AI assistant needs to know to compose pages, read from the blocks themselves.
 *
 * The catalogue is derived from the same Filament schemas the visual editor's inspector
 * renders, so it can never drift from what editors see: register a new block class and
 * the MCP server describes it, fields, options and all, on the next request.
 */
class BlockCatalogue
{
    /**
     * Guidance for the package's own primitives, which have no aiHints() of their own.
     *
     * @var array<string, string>
     */
    protected const PACKAGE_HINTS = [
        'section' => 'Columns. data.columns is 1–4 and data.ratio sets relative widths ("1-1", "1-2", "2-1", "1-1-1", "1-2-1", "1-1-1-1"). Children go into slots "col-0" … "col-{columns-1}". Use valign "center" for text beside an image. Columns stack on phones.',
        'list' => 'data.items is ONE string with one list item per line (newline separated). data.marker is bullet, number, check or none.',
        'quote' => 'A simple pull quote: data.text and data.cite.',
        'spacer' => 'Vertical breathing room: data.height is sm (16px), md (32px), lg (64px) or xl (96px). Use between a heading and a grid.',
        'divider' => 'A horizontal rule. No data.',
        'video' => 'A video file or a YouTube/Vimeo URL.',
        'embed' => 'An iframe from an allow-listed host (maps, forms, social posts).',
        'shortcode' => 'Runs a registered [shortcode]. Rarely needed.',
        'code' => 'Custom HTML/CSS/JS. Restricted to trusted users.',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $catalogue = [];

        foreach (static::registry()->all() as $type => $class) {
            $catalogue[$type] = static::describe($type);
        }

        return $catalogue;
    }

    /**
     * Only the block types the current user may author.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function available(): array
    {
        return array_filter(static::all(), fn (array $block): bool => $block['available']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(string $type): array
    {
        $class = static::registry()->find($type);

        if ($class === null) {
            return [];
        }

        $isContainer = is_subclass_of($class, Container::class);
        $defaults = static::registry()->defaults($type);

        return array_filter([
            'type' => $type,
            'label' => $class::label(),
            'category' => static::registry()->category($type),
            'description' => static::registry()->description($type),
            'available' => static::registry()->isVisible($type),
            'container' => $isContainer,
            'slots' => $isContainer ? static::slotDescription($type, $defaults) : null,
            'hints' => is_subclass_of($class, Block::class) ? $class::aiHints() : (self::PACKAGE_HINTS[$type] ?? null),
            'fields' => static::fields($class::schema()),
            'defaults' => $defaults ?: null,
            'inline_editable' => is_subclass_of($class, InlineEditable::class) ? array_keys($class::editables()) ?: null : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The design-wide options any block accepts besides its own data.
     *
     * @return array<string, mixed>
     */
    public static function styling(): array
    {
        return [
            'settings' => [
                'description' => 'Brand presets on any block: {"settings": {"padding": "lg", "background": "muted", "width": "narrow", "align": "center"}}. Prefer these over custom styles.',
                'tokens' => FilamentPageBuilderPlugin::get()->getStyleTokens(),
            ],
            'style' => [
                'description' => 'Exact styles (use sparingly): {"style": {"text_align": "center", "max_width": 640, "max_width_unit": "px", "margin_top": 32, "background_color": "#f5f3ff", "border_radius": 24}}.',
                'fields' => collect(BlockStyle::fields())->map(fn (mixed $kind): string => is_array($kind) ? implode('|', $kind) : (string) $kind)->all(),
            ],
            'anchor' => 'An id for in-page links, e.g. {"anchor": "pricing"} makes #pricing work.',
            'icons' => [
                'description' => 'Icon fields take any Heroicon as "hero-{style}-{name}": style o (outline), s (solid) or m (mini), e.g. "hero-o-rocket-launch". Good first choices are listed here.',
                'suggestions' => collect(array_keys(Fields::ICONS))->map(fn (string $icon): string => "hero-o-{$icon}")->all(),
            ],
            'highlight' => 'In headings, hero headlines, card titles and CTA titles, wrap a word in *asterisks* to paint it in the brand gradient.',
            'links' => 'Link fields come in groups: {prefix}_type ("page" | "file" | "url" | "none"), {prefix}_page (a page id; preferred, it follows the page when its slug changes), {prefix}_file (the id of any file in the media library, e.g. a PDF), {prefix}_url and {prefix}_new_tab. In rich text, link to a library file with its address /files/{id}.',
            'images' => 'Image fields take the id of an image in the media library (see list_images); alt text and captions live with the image. To use a new picture, call upload_image_from_url first. To frame an image for one block only, use an object instead of the bare id: {"asset": "<id>", "crop": {"x": 0.1, "y": 0, "width": 0.8, "height": 0.6}, "aspect": "16/9", "rotate": 90, "flip_x": false, "exposure": 10, "contrast": -5, "grayscale": false}. Crop values are fractions (0–1) of the original; the original is never changed.',
        ];
    }

    /**
     * Check block data against the block's own fields: unknown keys and values outside
     * a field's options are refused with a message an assistant can act on.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public static function validate(string $type, array $data): array
    {
        $description = static::describe($type);

        if ($description === []) {
            return ["Unknown block type \"{$type}\"."];
        }

        return static::checkFields($type, $description['fields'] ?? [], $data);
    }

    /**
     * Check data against described fields (fields()), a block's or a layout's ($owner, in the
     * messages): unknown keys and values outside a field's options are refused.
     *
     * @param  array<int, array<string, mixed>>  $described
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public static function checkFields(string $owner, array $described, array $data): array
    {
        $fields = collect($described)->keyBy('name');
        $errors = [];

        foreach ($data as $key => $value) {
            $field = $fields->get($key);

            if ($field === null) {
                $errors[] = $fields->isEmpty()
                    ? "\"{$owner}\" has no fields."
                    : "\"{$owner}\" has no field \"{$key}\". Its fields are: ".$fields->keys()->implode(', ').'.';

                continue;
            }

            if (str_starts_with($field['type'], 'image asset id') && filled($value) && ($notImages = static::nonImageAssets($value)) !== []) {
                $errors[] = "\"{$owner}.{$key}\" takes images only; ".implode(', ', $notImages).' is another kind of file.';

                continue;
            }

            if ($field['type'] === 'icon' && filled($value) && (! is_string($value) || SiteIcon::name($value) === null)) {
                $errors[] = "\"{$owner}.{$key}\" must be a Heroicon name such as \"hero-o-sparkles\".";

                continue;
            }

            if ($value === null || $value === '' || ! isset($field['options']) || ($field['references'] ?? null)) {
                continue;
            }

            $allowed = array_map('strval', array_keys($field['options']));
            $values = ($field['multiple'] ?? false) && is_array($value) ? $value : [$value];

            foreach ($values as $item) {
                if (! is_scalar($item) || ! in_array((string) $item, $allowed, true)) {
                    $errors[] = "\"{$owner}.{$key}\" must be one of: ".implode(', ', $allowed).'.';

                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * The titles of the media library files in an image field's value that aren't images.
     *
     * @return array<int, string>
     */
    protected static function nonImageAssets(mixed $value): array
    {
        $ids = collect(is_array($value) && array_is_list($value) ? $value : [$value])
            ->map(fn (mixed $item): ?string => ImageEdits::assetId($item))
            ->filter()
            ->all();

        return Asset::query()
            ->whereKey($ids)
            ->whereNot(fn ($query) => $query->ofType(AssetType::Image))
            ->pluck('title')
            ->map(fn (string $title): string => "\"{$title}\"")
            ->all();
    }

    /**
     * Filament fields as an assistant reads them: name, type, label, options and help.
     *
     * @param  array<int, mixed>  $components
     * @return array<int, array<string, mixed>>
     */
    public static function fields(array $components): array
    {
        $fields = [];

        try {
            $components = Schema::make()->components($components)->getComponents(withHidden: true);
        } catch (Throwable) {
            return [];
        }

        foreach ($components as $component) {
            if ($component instanceof Field) {
                $fields[] = static::field($component);

                continue;
            }

            if ($component instanceof Component) {
                try {
                    array_push($fields, ...static::fields($component->getDefaultChildComponents()));
                } catch (Throwable) {
                    //
                }
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function field(Field $field): array
    {
        $name = $field->getName();

        $description = [
            'name' => $name,
            'type' => match (true) {
                $field instanceof AssetPicker && ! $field->takesOnlyImages() => 'file asset id',
                $field instanceof AssetPicker => $field->isMultiple() ? 'image asset ids (array)' : 'image asset id',
                $field instanceof RichEditor => 'html',
                $field instanceof MarkdownEditor => 'markdown',
                $field instanceof Textarea => 'text (multi-line)',
                $field instanceof TextInput => $field->isNumeric() ? 'number' : 'text',
                $field instanceof Toggle => 'boolean',
                $field instanceof FileUpload => $field->isMultiple() ? 'image paths (array)' : 'image path',
                $field instanceof Repeater => 'list of objects',
                $field instanceof ColorPicker => 'colour',
                $field instanceof Slider => 'number',
                $field instanceof DateTimePicker => 'datetime',
                $field instanceof TagsInput => 'list of strings',
                $field instanceof IconPickerField => 'icon',
                $field instanceof Select, $field instanceof ToggleButtons, $field instanceof Radio, $field instanceof CheckboxList => 'choice',
                default => Str::snake(class_basename($field), ' '),
            },
            'label' => static::safely(fn () => strip_tags((string) $field->getLabel())),
        ];

        if (str_ends_with($name, '_page') || $name === 'page_ids') {
            $description['references'] = 'page id'.($name === 'page_ids' ? 's' : '');
        } elseif (method_exists($field, 'getOptions')) {
            $options = static::safely(fn () => $field->getOptions());

            if (is_array($options) && $options !== []) {
                $description['options'] = array_map(fn (mixed $label): string => trim(strip_tags((string) $label)), $options);
            }
        }

        if (method_exists($field, 'isMultiple') && static::safely(fn () => $field->isMultiple())) {
            $description['multiple'] = true;
        }

        if ($field instanceof Repeater) {
            $description['item_fields'] = static::fields($field->getDefaultChildComponents());
        }

        $help = static::safely(fn () => $field->getHelperText());

        if (is_string($help) && $help !== '') {
            $description['help'] = $help;
        }

        return array_filter($description, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    protected static function slotDescription(string $type, array $defaults): string
    {
        return match ($type) {
            'section' => 'col-0 … col-{columns-1} (one slot per column)',
            default => implode(', ', static::registry()->slots($type, $defaults)),
        };
    }

    protected static function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }

    protected static function registry(): BlockRegistry
    {
        return app(BlockRegistries::class)->for('admin');
    }
}
