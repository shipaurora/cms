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
 * Appearance (not per site, not per person). Aurora's own is Borealis (shipaurora/borealis-theme).
 *
 *     Themes::register('borealis', name: 'Borealis', stylesheet: __DIR__.'/../resources/dist/theme.css', package: 'shipaurora/borealis-theme', preview: __DIR__.'/../resources/preview.webp');
 *
 * A theme made for light and dark can give a picture of each, and the picker shows the one
 * for the mode the person is in: preview: ['light' => …/preview-light.webp, 'dark' => …/preview-dark.webp].
 */
class Themes
{
    public const SETTING = 'admin_theme';

    /**
     * Where the theme packages are listed: Packagist, by the keyword they share.
     */
    public const BROWSE_URL = 'https://packagist.org/search/?tags=aurora-cms-theme';

    /**
     * @var array<string, array{id: string, name: string, description: ?string, stylesheet: ?string, vite: ?string, previews: array<string, string>, modes: array<int, string>}>
     */
    protected static array $themes = [];

    /**
     * Registering an id again replaces that theme.
     *
     * @param  string|null  $stylesheet  A prebuilt stylesheet (registered with Filament's assets)…
     * @param  string|null  $vite  …or a Vite entry the Laravel app builds itself.
     * @param  string|array<string, string>|null  $preview  A picture of the admin in it, for choosing it: one
     *                                                      for every mode, or one per mode (light => file, dark => file).
     * @param  array<int, string>  $modes  The modes it's made for: ['light', 'dark'], or one of them.
     *                                     Only with both can people switch (the user menu, ⌘K).
     */
    public static function register(string $id, string $name, ?string $description = null, ?string $stylesheet = null, ?string $vite = null, string|array|null $preview = null, ?string $package = null, array $modes = ['light', 'dark']): void
    {
        $modes = array_values(array_intersect(['light', 'dark'], $modes)) ?: ['light', 'dark'];

        $previews = static::previewsFor($preview, $modes);

        static::$themes[$id] = compact('id', 'name', 'description', 'stylesheet', 'vite', 'previews', 'modes');

        if ($stylesheet !== null) {
            FilamentAsset::register([Theme::make($id, $stylesheet)], package: $package ?? 'app');
        }
    }

    /**
     * A theme's picture for each mode it's made for: its own where it has one, else the other
     * mode's (a theme with a light picture only shows it in dark mode too). Files that aren't
     * there are left out.
     *
     * @param  string|array<string, string>|null  $preview
     * @param  array<int, string>  $modes
     * @return array<string, string> mode => file
     */
    protected static function previewsFor(string|array|null $preview, array $modes): array
    {
        $given = is_array($preview)
            ? array_filter(array_intersect_key($preview, array_flip(['light', 'dark'])), fn (mixed $file): bool => is_string($file) && is_file($file))
            : (is_string($preview) && is_file($preview) ? array_fill_keys($modes, $preview) : []);

        if ($given === []) {
            return [];
        }

        return collect($modes)->mapWithKeys(fn (string $mode): array => [$mode => $given[$mode] ?? reset($given)])->all();
    }

    /**
     * Take a theme away again (a site that doesn't offer a package's, or a test).
     */
    public static function forget(string $id): void
    {
        unset(static::$themes[$id]);
    }

    /**
     * @return array<string, array{id: string, name: string, description: ?string, stylesheet: ?string, vite: ?string, previews: array<string, string>, modes: array<int, string>}>
     */
    public static function all(): array
    {
        return static::$themes;
    }

    /**
     * The theme in use: the one chosen in Settings, else config('aurora.theme') (AURORA_THEME),
     * else Borealis, else the first there is.
     */
    public static function active(): ?string
    {
        $chosen = rescue(fn (): mixed => Setting::get(self::SETTING), null, report: false);

        foreach ([$chosen, config('aurora.theme'), 'borealis'] as $id) {
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
        $vite = $theme['vite'] ?? null;

        return match (true) {
            $theme === null => '',
            $vite !== null => app(Vite::class)($vite)->toHtml(),
            default => FilamentAsset::getTheme($theme['id'])?->getHtml()->toHtml() ?? '',
        };
    }
}
