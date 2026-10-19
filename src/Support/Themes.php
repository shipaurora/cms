<?php

namespace Aurora\Support;

use Aurora\Models\Setting;
use Filament\Support\Assets\Theme;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Foundation\Vite;

/**
 * The admin's looks (the CMS's, never the website's): themes register here (a package's
 * prebuilt stylesheet, or a Vite entry the Laravel app builds), each with a name, a
 * description and a picture, and one is chosen for the whole admin under Settings ›
 * Appearance (not per site, not per person). Aurora's own is aurora/aurora's.
 *
 *     Themes::register('aurora', name: 'Aurora', stylesheet: __DIR__.'/../resources/dist/theme.css', package: 'aurora/aurora', preview: __DIR__.'/../resources/preview.webp');
 */
class Themes
{
    public const SETTING = 'admin_theme';

    /**
     * @var array<string, array{id: string, name: string, description: ?string, stylesheet: ?string, vite: ?string, preview: ?string, modes: array<int, string>}>
     */
    protected static array $themes = [];

    /**
     * Themes the Laravel app compiles itself, by id: their Vite entries.
     *
     * @var array<string, string>
     */
    protected static array $builds = [];

    /**
     * Registering an id again replaces that theme.
     *
     * @param  string|null  $stylesheet  A prebuilt stylesheet (registered with Filament's assets)…
     * @param  string|null  $vite  …or a Vite entry the Laravel app builds itself.
     * @param  string|null  $preview  A picture of the admin in it, for choosing it.
     * @param  array<int, string>  $modes  The modes it's made for: ['light', 'dark'], or one of them.
     *                                     Only with both can people switch (the user menu, ⌘K).
     */
    public static function register(string $id, string $name, ?string $description = null, ?string $stylesheet = null, ?string $vite = null, ?string $preview = null, ?string $package = null, array $modes = ['light', 'dark']): void
    {
        $modes = array_values(array_intersect(['light', 'dark'], $modes)) ?: ['light', 'dark'];

        static::$themes[$id] = compact('id', 'name', 'description', 'stylesheet', 'vite', 'preview', 'modes');

        if ($stylesheet !== null) {
            FilamentAsset::register([Theme::make($id, $stylesheet)], package: $package ?? 'app');
        }
    }

    /**
     * The Laravel app compiles this theme itself: the theme's styles together with the classes
     * of the website's blocks, so the visual editor's canvas shows them as the website does
     * (a prebuilt stylesheet can't know them). Still the same theme in Settings › Appearance.
     *
     *     Themes::build('aurora', vite: 'resources/css/filament/admin/theme.css');
     */
    public static function build(string $id, string $vite): void
    {
        static::$builds[$id] = $vite;
    }

    /**
     * Take a theme away again (a site that doesn't offer a package's, or a test).
     */
    public static function forget(string $id): void
    {
        unset(static::$themes[$id], static::$builds[$id]);
    }

    /**
     * @return array<string, array{id: string, name: string, description: ?string, stylesheet: ?string, vite: ?string, preview: ?string}>
     */
    public static function all(): array
    {
        return static::$themes;
    }

    /**
     * The theme in use: the one chosen in Settings, else config('aurora.theme') (AURORA_THEME),
     * else Aurora's, else the first there is.
     */
    public static function active(): ?string
    {
        $chosen = rescue(fn (): mixed => Setting::get(self::SETTING), null, report: false);

        foreach ([$chosen, config('aurora.theme'), 'aurora'] as $id) {
            if (is_string($id) && isset(static::$themes[$id])) {
                return $id;
            }
        }

        return array_key_first(static::$themes);
    }

    /**
     * The modes the active theme is made for: light, dark, or both.
     *
     * @return array<int, string>
     */
    public static function modes(): array
    {
        return static::$themes[static::active()]['modes'] ?? ['light', 'dark'];
    }

    /**
     * Whether people may switch between light and dark: only when the theme has both.
     */
    public static function switchesMode(): bool
    {
        return count(static::modes()) > 1;
    }

    /**
     * The mode a person sees: theirs (light, dark or system), when the theme has it, else
     * the one the theme is made for.
     */
    public static function modeFor(?string $preferred): string
    {
        return static::switchesMode()
            ? (in_array($preferred, ['light', 'dark', 'system'], true) ? $preferred : 'dark')
            : static::modes()[0];
    }

    /**
     * The active theme's stylesheet, for the admin's head (AdminPanelProvider).
     */
    public static function html(): string
    {
        $theme = static::$themes[static::active()] ?? null;
        $vite = $theme === null ? null : static::$builds[$theme['id']] ?? $theme['vite'];

        return match (true) {
            $theme === null => '',
            $vite !== null => app(Vite::class)($vite)->toHtml(),
            default => FilamentAsset::getTheme($theme['id'])?->getHtml()->toHtml() ?? '',
        };
    }
}
