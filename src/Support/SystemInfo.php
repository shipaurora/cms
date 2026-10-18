<?php

namespace Aurora\Support;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;

/**
 * What the CMS runs on, for Settings › System › System info: versions and the services it
 * uses, with what needs looking at.
 */
class SystemInfo
{
    /**
     * @return array<int, array{label: string, value: string, warning: ?string}>
     */
    public static function rows(): array
    {
        $version = fn (string $package): string => InstalledVersions::isInstalled($package) ? (string) InstalledVersions::getPrettyVersion($package) : '—';

        return [
            ['label' => 'Aurora', 'value' => $version('aurora/cms'), 'warning' => null],
            ['label' => 'Laravel', 'value' => app()->version(), 'warning' => null],
            ['label' => 'Filament', 'value' => $version('filament/filament'), 'warning' => null],
            ['label' => 'PHP', 'value' => PHP_VERSION, 'warning' => null],
            ['label' => __('Database'), 'value' => DB::connection()->getDriverName(), 'warning' => null],
            ['label' => __('Cache'), 'value' => (string) config('cache.default'), 'warning' => config('cache.default') === 'array' ? __('Nothing is kept between visits: the page cache can’t work.') : null],
            ['label' => __('Queue'), 'value' => (string) config('queue.default'), 'warning' => config('queue.default') === 'sync' ? __('Image formats and webhooks are done while people wait. A queue worker makes the admin faster.') : null],
            ['label' => __('Email'), 'value' => (string) config('mail.default'), 'warning' => in_array(config('mail.default'), ['log', 'array'], true) ? __('Emails aren’t sent, only written to the log.') : null],
            ['label' => __('Search'), 'value' => (string) config('scout.driver'), 'warning' => null],
            ['label' => __('Files'), 'value' => (string) config('media-library.disk_name', config('filesystems.default')), 'warning' => is_link(public_path('storage')) || ! is_dir(storage_path('app/public')) ? null : __('public/storage isn’t linked: run php artisan storage:link.')],
            ['label' => __('Environment'), 'value' => app()->environment(), 'warning' => app()->isProduction() && config('app.debug') ? __('Debug mode is on in production: visitors can see error details. Set APP_DEBUG=false.') : null],
        ];
    }
}
