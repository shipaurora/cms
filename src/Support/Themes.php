<?php

namespace Aurora\Support;

use Aurora\Models\Setting;
use Composer\InstalledVersions;
use Filament\Support\Assets\Theme;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Foundation\Vite;

/**
 * The admin's looks (the CMS's, never the website's): themes register here (a package's
 * prebuilt stylesheet, or a Vite entry the Laravel app builds), each with a name, a
 * description and a picture, and one is chosen for the whole admin under Settings ›
 * Appearance (not per site, not per person). Aurora's own is Borealis (shipaurora/borealis).
 *
 *     Themes::register('borealis', name: 'Borealis', stylesheet: __DIR__.'/../resources/dist/theme.css', package: 'shipaurora/borealis', preview: __DIR__.'/../resources/preview.webp');
 *
 * A theme made for light and dark can give a picture of each, and the picker shows the one
 * for the mode the person is in: preview: ['light' => …/preview-light.webp, 'dark' => …/preview-dark.webp].
 *
 * The picker shows a theme's version beside its name: the installed release of the Composer
 * package it comes from, or the version: it registers with.
 */
class Themes
{
    public const SETTING = 'admin_theme';

    /**
     * Where the theme packages are listed: Packagist, by the keyword they share.
     */
    public const BROWSE_URL = 'https://packagist.org/search/?tags=aurora-cms-theme';

    /**
     * @var array<string, array{id: string, name: string, description: ?string, stylesheet: ?string, vite: ?string, previews: array<string, string>, modes: array<int, string>, package: ?string, version: ?string}>
     */
    protected static array $themes = [];

    /**
     * Versions found in Composer's list of installed packages, by theme id.
     *
     * @var array<string, ?string>
     */
    protected static array $versions = [];

    /**
     * Registering an id again replaces that theme.
     *
     * @param  string|null  $stylesheet  A prebuilt stylesheet (registered with Filament's assets)…
     * @param  string|null  $vite  …or a Vite entry the Laravel app builds itself.
     * @param  string|array<string, string>|null  $preview  A picture of the admin in it, for choosing it: one
     *                                                      for every mode, or one per mode (light => file, dark => file).
     * @param  array<int, string>  $modes  The modes it's made for: ['light', 'dark'], or one of them.
     *                                     Only with both can people switch (the user menu, ⌘K).
     * @param  string|null  $version  Shown beside its name; by default the release Composer installed.
     */
    public static function register(string $id, string $name, ?string $description = null, ?string $stylesheet = null, ?string $vite = null, string|array|null $preview = null, ?string $package = null, array $modes = ['light', 'dark'], ?string $version = null): void
    {
        $modes = array_values(array_intersect(['light', 'dark'], $modes)) ?: ['light', 'dark'];

        $previews = static::previewsFor($preview, $modes);

        static::$themes[$id] = compact('id', 'name', 'description', 'stylesheet', 'vite', 'previews', 'modes', 'package', 'version');
        unset(static::$versions[$id]);

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
        unset(static::$themes[$id], static::$versions[$id]);
    }

    /**
     * A theme's version for the picker ("v1.0.6"): the one it registered with, else the release
     * Composer installed of its package, found by name or as the package its stylesheet or
     * picture is in (a theme's asset name needn't be its package's). Null for a theme the app
     * builds itself, or a package installed from a branch.
     */
    public static function version(string $id): ?string
    {
        $theme = static::$themes[$id] ?? null;

        if ($theme === null) {
            return null;
        }

        $version = $theme['version'] ?? (static::$versions[$id] ??= static::installedVersion($theme));

        return $version === null ? null : 'v'.ltrim($version, 'vV');
    }

    /**
     * @param  array{stylesheet: ?string, previews: array<string, string>, package: ?string}  $theme
     */
    protected static function installedVersion(array $theme): ?string
    {
        // A name the package replaces (Borealis's old one) is installed, but has no version of its own.
        $package = $theme['package'] !== null && InstalledVersions::isInstalled($theme['package']) && InstalledVersions::getPrettyVersion($theme['package']) !== null ? $theme['package'] : null;

        if ($package === null) {
            $file = $theme['stylesheet'] ?? (reset($theme['previews']) ?: null);
            $file = $file === null ? false : realpath($file);
            $root = InstalledVersions::getRootPackage()['name'];

            foreach ($file === false ? [] : InstalledVersions::getInstalledPackages() as $installed) {
                // Names only replaced or provided by another package have no path of their own.
                $path = $installed === $root ? null : InstalledVersions::getInstallPath($installed);
                $path = $path === null ? false : realpath($path);

                if ($path !== false && str_starts_with($file, $path.DIRECTORY_SEPARATOR)) {
                    $package = $installed;
                    break;
                }
            }
        }

        $version = $package === null ? null : InstalledVersions::getPrettyVersion($package);

        return $version === null || str_starts_with($version, 'dev-') || str_ends_with($version, '-dev') ? null : $version;
    }

    /**
     * @return array<string, array{id: string, name: string, description: ?string, stylesheet: ?string, vite: ?string, previews: array<string, string>, modes: array<int, string>, package: ?string, version: ?string}>
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
