<?php

namespace Aurora\Support;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Whether a newer Aurora is out: the installed release against the newest one on Packagist,
 * where Composer installs it from. Asked at most every few hours, and quietly: without the
 * network, or from a development checkout, there's simply nothing to say.
 */
class Updates
{
    public const PACKAGE = 'shipaurora/cms';

    /**
     * Where Packagist lists the package's releases (Composer's own metadata).
     */
    public const METADATA_URL = 'https://repo.packagist.org/p2/shipaurora/cms.json';

    protected const CACHE_KEY = 'aurora.latest-release';

    /**
     * How long an answer is kept: hours when Packagist answered, an hour when it didn't.
     */
    protected const KEEP_SECONDS = 6 * 3600;

    protected const RETRY_SECONDS = 3600;

    /**
     * The release to take as installed instead of Composer's (Updates::fakeInstalled()).
     */
    protected static ?string $fakeInstalled = null;

    /**
     * The installed release ("v1.0.4"), or null when Aurora isn't installed as a release
     * (a development branch, or a path repository).
     */
    public static function installed(): ?string
    {
        if (static::$fakeInstalled !== null) {
            return static::$fakeInstalled;
        }

        $version = InstalledVersions::isInstalled(self::PACKAGE) ? (string) InstalledVersions::getPrettyVersion(self::PACKAGE) : null;

        return $version !== null && static::isRelease($version) ? $version : null;
    }

    /**
     * The newest release on Packagist, or null when it isn't known. With $ask false, only
     * what was found out before: nothing waits on the network (the settings menu's badge).
     */
    public static function latest(bool $ask = true): ?string
    {
        $known = Cache::get(self::CACHE_KEY);

        if (is_array($known) || ! $ask) {
            return is_array($known) ? $known['version'] : null;
        }

        $version = static::fetch();
        Cache::put(self::CACHE_KEY, ['version' => $version], $version === null ? self::RETRY_SECONDS : self::KEEP_SECONDS);

        return $version;
    }

    /**
     * The newer release to update to, or null when the installed one is the newest (or
     * either isn't known).
     */
    public static function available(bool $ask = true): ?string
    {
        $installed = static::installed();
        $latest = $installed === null ? null : static::latest($ask);

        return $latest !== null && version_compare(static::number($latest), static::number($installed), '>') ? $latest : null;
    }

    /**
     * What changed between the installed release and the newest one, on GitHub.
     */
    public static function changesUrl(string $latest): ?string
    {
        $installed = static::installed();

        return $installed === null ? null : "https://github.com/shipaurora/cms/compare/{$installed}...{$latest}";
    }

    /**
     * Take this release as installed, whatever Composer installed (null: Composer's again). For
     * tests, which run from a development checkout as often as from a release.
     */
    public static function fakeInstalled(?string $version): void
    {
        static::$fakeInstalled = $version;
    }

    /**
     * Forget the answer, to ask again next time.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The newest stable release Packagist lists, or null when it can't be asked.
     */
    protected static function fetch(): ?string
    {
        try {
            $releases = Http::timeout(3)->acceptJson()->get(self::METADATA_URL)->throw()->json('packages.'.self::PACKAGE, []);
        } catch (Throwable) {
            return null;
        }

        $versions = array_filter(array_column(is_array($releases) ? $releases : [], 'version'), fn (mixed $version): bool => is_string($version) && static::isRelease($version));

        usort($versions, fn (string $a, string $b): int => version_compare(static::number($b), static::number($a)));

        return $versions[0] ?? null;
    }

    /**
     * A stable release ("v1.0.4" or "1.0.4"): not a branch, an alpha or a release candidate.
     */
    protected static function isRelease(string $version): bool
    {
        return (bool) preg_match('/^v?\d+\.\d+\.\d+$/', $version);
    }

    protected static function number(string $version): string
    {
        return ltrim($version, 'v');
    }
}
