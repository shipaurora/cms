<?php

namespace Aurora\Support;

use Aurora\Models\Setting;
use Filament\Support\Assets\Theme;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Foundation\Vite;

/**
 * The admin's looks: themes register here (a package's prebuilt stylesheet, or a site's own
 * Vite build), each with a name, a description and a picture, and one is chosen for the
 * whole admin under Settings › Appearance (not per site, not per person). Aurora's own is
 * aurora/aurora's.
 *
 *     Themes::register('aurora', name: 'Aurora', stylesheet: __DIR__.'/../resources/dist/theme.css', package: 'aurora/aurora', preview: __DIR__.'/../resources/preview.webp');
 */
class Themes
{
    public const SETTING = 'admin_theme';

    /**
     * @var array<string, array{id: string, name: string, description: ?string, stylesheet: ?string, vite: ?string, preview: ?string}>
     */
    protected static array $themes = [];

    /**
     * Registering an id again replaces that theme.
     *
     * @param  string|null  $stylesheet  A prebuilt stylesheet (registered with Filament's assets)…
     * @param  string|null  $vite  …or a Vite entry the site builds ("resources/css/filament/admin/theme.css").
     * @param  string|null  $preview  A picture of the admin in it, for choosing it.
     */
    public static function register(string $id, string $name, ?string $description = null, ?string $stylesheet = null, ?string $vite = null, ?string $preview = null, ?string $package = null): void
    {
        static::$themes[$id] = compact('id', 'name', 'description', 'stylesheet', 'vite', 'preview');

        if ($stylesheet !== null) {
            FilamentAsset::register([Theme::make($id, $stylesheet)], package: $package);
        }
    }

    /**
     * Take a theme away again (a site that doesn't offer a package's, or a test).
     */
    public static function forget(string $id): void
    {
        unset(static::$themes[$id]);
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
     * The active theme's stylesheet, for the admin's head (AdminPanelProvider).
     */
    public static function html(): string
    {
        $theme = static::$themes[static::active()] ?? null;

        return match (true) {
            $theme === null => '',
            $theme['vite'] !== null => app(Vite::class)($theme['vite'])->toHtml(),
            default => FilamentAsset::getTheme($theme['id'])?->getHtml()->toHtml() ?? '',
        };
    }
}
