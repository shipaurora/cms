<?php

namespace Aurora\Tests;

use Aurora\Database\Seeders\RolesAndPermissionsSeeder;
use Aurora\Models\Locale;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Providers\AdminPanelProvider;
use Aurora\Providers\AuroraServiceProvider;
use Aurora\Support\TranslationSync;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Testbench;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * Aurora in a Laravel app of its own (Testbench), with a website built on it: the workbench's
 * blocks and settings tabs. Tests work in the admin on the default site, as someone who just
 * signed in does, and never reach the internet: a request no test faked fails instead of
 * slowing the run.
 */
abstract class TestCase extends Testbench
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // The keys AI apps' access tokens are signed with, as aurora:install makes them.
        if (! is_file(storage_path('oauth-private.key'))) {
            Artisan::call('passport:keys');
        }

        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            Filament::setCurrentPanel('admin');
            Filament::setTenant(Site::default(), isQuiet: true);
        }
    }

    /**
     * The packages Aurora is built on register themselves, as in an app (package discovery).
     *
     * @return array<int, string>
     */
    public function ignorePackageDiscoveriesFrom(): array
    {
        return [];
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AuroraServiceProvider::class, AdminPanelProvider::class, WorkbenchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The workbench website's views: its blocks.
        $app['config']->set('view.paths', [...$app['config']->get('view.paths', []), __DIR__.'/../workbench/resources/views']);
        // Compiled views of their own per test process (ParaTest's TEST_TOKEN): clearing the
        // caches in one (view:clear) never pulls a view from under another.
        $compiled = sys_get_temp_dir().'/aurora-tests-views-'.(getenv('TEST_TOKEN') ?: '0');
        File::ensureDirectoryExists($compiled);
        $app['config']->set('view.compiled', $compiled);
        // Foreign keys hold, as in a site's database: Testbench's SQLite leaves them off.
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
    }

    /**
     * A file the tests upload or import: tests/fixtures/{name}.
     */
    protected function fixture(string $name): string
    {
        return __DIR__.'/fixtures/'.$name;
    }

    protected function userWithRole(string $role, array $attributes = []): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return tap(User::factory()->create($attributes))->assignRole($role);
    }

    /**
     * English (the default) and Norwegian, with the labels imported from the lang files: a
     * website in two languages.
     */
    protected function withEnglishAndNorwegian(): void
    {
        Locale::query()->updateOrCreate(['code' => 'en'], ['name' => 'English', 'is_default' => true, 'is_active' => true, 'sort' => 1]);
        Locale::query()->updateOrCreate(['code' => 'nb'], ['name' => 'Norsk bokmål', 'is_active' => true, 'sort' => 2]);

        app(TranslationSync::class)->import();
    }
}
