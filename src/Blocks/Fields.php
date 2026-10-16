<?php

namespace Aurora\Blocks;

use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Filament\Forms\Components\PagePicker;
use Aurora\Models\Page;
use Aurora\Support\ContentLocale;
use Aurora\Support\CurrentSite;
use Aurora\Support\SiteIcon;
use CarlJanzell\FilamentPageBuilder\Support\SafeUrl;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Wallacemartinss\FilamentIconPicker\Forms\Components\IconPickerField;

/**
 * Field groups several blocks share, so a link or an icon is edited the same way everywhere.
 */
class Fields
{
    /**
     * The short icon names blocks stored before the icon picker, by Heroicon name. Still
     * drawn (see SiteIcon), and offered to AI assistants as good first choices.
     *
     * @var array<string, string>
     */
    public const ICONS = [
        'sparkles' => 'Sparkles',
        'bolt' => 'Bolt',
        'rocket-launch' => 'Rocket',
        'light-bulb' => 'Idea',
        'cursor-arrow-rays' => 'Click',
        'paint-brush' => 'Design',
        'swatch' => 'Swatch',
        'puzzle-piece' => 'Puzzle',
        'cube-transparent' => 'Cube',
        'squares-2x2' => 'Grid',
        'code-bracket' => 'Code',
        'command-line' => 'Terminal',
        'cpu-chip' => 'Chip',
        'server-stack' => 'Servers',
        'cloud' => 'Cloud',
        'globe-alt' => 'Globe',
        'shield-check' => 'Shield',
        'lock-closed' => 'Lock',
        'key' => 'Key',
        'finger-print' => 'Fingerprint',
        'chart-bar' => 'Bar chart',
        'chart-pie' => 'Pie chart',
        'presentation-chart-line' => 'Growth',
        'arrow-trending-up' => 'Trending',
        'currency-dollar' => 'Money',
        'banknotes' => 'Banknotes',
        'shopping-bag' => 'Shopping',
        'gift' => 'Gift',
        'heart' => 'Heart',
        'star' => 'Star',
        'trophy' => 'Trophy',
        'face-smile' => 'Smile',
        'user-group' => 'Team',
        'chat-bubble-left-right' => 'Chat',
        'envelope' => 'Email',
        'phone' => 'Phone',
        'map-pin' => 'Location',
        'calendar-days' => 'Calendar',
        'clock' => 'Clock',
        'academic-cap' => 'Education',
        'book-open' => 'Book',
        'camera' => 'Camera',
        'film' => 'Film',
        'musical-note' => 'Music',
        'wrench-screwdriver' => 'Tools',
        'adjustments-horizontal' => 'Settings',
        'check-badge' => 'Verified',
        'fire' => 'Fire',
        'sun' => 'Sun',
        'moon' => 'Moon',
    ];

    /**
     * Brand tones a block can wear. Names, not colours: the palette comes from Site settings.
     *
     * @var array<string, string>
     */
    public const TONES = [
        'brand' => 'Brand',
        'accent' => 'Accent',
        'neutral' => 'Neutral',
        'dark' => 'Dark',
        'success' => 'Success',
        'warning' => 'Warning',
        'danger' => 'Danger',
    ];

    /**
     * An icon for the website, picked from every Heroicon in a searchable grid. Stored as the
     * icon's name ("hero-o-sparkles"); see SiteIcon for drawing it.
     */
    public static function icon(string $name = 'icon', string $label = 'Icon'): IconPickerField
    {
        return IconPickerField::make($name)
            ->label(__($label))
            ->allowedSets([SiteIcon::SET])
            ->showSetFilter(false)
            ->placeholder(__('Choose an icon'));
    }

    public static function tone(string $name = 'tone', string $default = 'brand'): ToggleButtons
    {
        return ToggleButtons::make($name)
            ->label(__('Tone'))
            ->options(self::TONES)
            ->colors([
                'brand' => 'primary',
                'accent' => 'info',
                'neutral' => 'gray',
                'dark' => 'gray',
                'success' => 'success',
                'warning' => 'warning',
                'danger' => 'danger',
            ])
            ->default($default)
            ->inline();
    }

    /**
     * A link that points at a page (and follows it when its slug changes), a file in the
     * media library (and follows it when the file is replaced), or a URL.
     *
     * @return array<int, mixed>
     */
    public static function link(string $prefix = 'link', string $label = 'Link'): array
    {
        return [
            Fieldset::make($label)
                ->columns(1)
                ->schema([
                    ToggleButtons::make("{$prefix}_type")
                        ->hiddenLabel()
                        ->options(['page' => __('Page'), 'file' => __('File'), 'url' => __('URL'), 'none' => __('None')])
                        ->icons(['page' => 'phosphor-file-text', 'file' => 'phosphor-paperclip', 'url' => 'phosphor-link', 'none' => 'phosphor-prohibit'])
                        ->default('page')
                        ->inline()
                        ->grouped()
                        ->live(),
                    // The chosen page, and the list in a dialog: block forms sit in the narrow canvas sidebar.
                    PagePicker::make("{$prefix}_page")
                        ->hiddenLabel()
                        ->inModal()
                        ->visible(fn (Get $get): bool => ($get("{$prefix}_type") ?? 'page') === 'page'),
                    AssetPicker::make("{$prefix}_file")
                        ->hiddenLabel()
                        ->anyFile()
                        ->visible(fn (Get $get): bool => $get("{$prefix}_type") === 'file'),
                    TextInput::make("{$prefix}_url")
                        ->hiddenLabel()
                        ->placeholder('https://… or #anchor')
                        ->prefixIcon('phosphor-link')
                        ->maxLength(2048)
                        ->visible(fn (Get $get): bool => $get("{$prefix}_type") === 'url'),
                    Toggle::make("{$prefix}_new_tab")
                        ->label(__('Open in a new tab'))
                        ->visible(fn (Get $get): bool => $get("{$prefix}_type") !== 'none'),
                ]),
        ];
    }

    /**
     * The address a link field group points at, or null when it points nowhere.
     *
     * @param  array<string, mixed>  $data
     */
    public static function href(array $data, string $prefix = 'link'): ?string
    {
        $type = $data["{$prefix}_type"] ?? (filled($data["{$prefix}_url"] ?? null) ? 'url' : 'page');

        return match ($type) {
            'page' => self::pagePath($data["{$prefix}_page"] ?? null),
            'file' => is_string($id = $data["{$prefix}_file"] ?? null) && Str::isUuid($id) ? route('files.show', $id, absolute: false) : null,
            'url' => filled($data["{$prefix}_url"] ?? null) ? SafeUrl::href($data["{$prefix}_url"]) : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function target(array $data, string $prefix = 'link'): ?string
    {
        return ($data["{$prefix}_new_tab"] ?? false) ? '_blank' : null;
    }

    protected static function pagePath(mixed $id): ?string
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        return static::pagePaths(ContentLocale::current(), CurrentSite::id())[$id] ?? null;
    }

    /**
     * Every page's address on a site in a language, looked up once per request (once() is
     * cleared between requests, queue jobs and tests).
     *
     * @return array<string, string>
     */
    protected static function pagePaths(string $locale, ?string $siteId): array
    {
        return once(fn (): array => Page::query()->get(['id', 'slug', 'is_homepage', 'locales'])->mapWithKeys(fn (Page $page): array => [$page->id => $page->path($locale)])->all());
    }

    public static function helpText(string $text): HtmlString
    {
        return new HtmlString('<span style="opacity:.75">'.e($text).'</span>');
    }
}
