<?php

namespace Aurora\Tests\Feature;

use Aurora\Models\LanguageLine;
use Aurora\Models\Locale;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\Layouts;
use Aurora\Tests\TestCase;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Client;

class InstallCmsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Where the installer writes .env during a test: never the real one.
     */
    protected string $environmentPath;

    /**
     * The site's own layout views before the test: the installer publishes Aurora's beside
     * them (never over them), and the ones it adds go again afterwards.
     *
     * @var array<int, string>
     */
    protected array $layoutViews = [];

    /**
     * The app's public folder during a test: empty, so the website's front end isn't built
     * (and the installer's published files and storage link land here, not in the site's).
     */
    protected string $publicPath;

    /**
     * Whether the test gave the app a package.json: a website with a front end to build.
     */
    protected bool $madePackageJson = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->environmentPath = sys_get_temp_dir().'/cms-install-'.Str::random(8);
        mkdir($this->environmentPath);
        file_put_contents($this->environmentPath.'/.env', "APP_NAME=Fjordkaffe\n");
        $this->app->useEnvironmentPath($this->environmentPath);
        $this->publicPath = sys_get_temp_dir().'/cms-install-public-'.Str::random(8);
        mkdir($this->publicPath);
        $this->app->usePublicPath($this->publicPath);
        // Pages opened after installing load no built assets: there are none in that folder.
        $this->withoutVite();
        $this->layoutViews = glob(Layouts::path().'/*.blade.php') ?: [];
        $this->withFrontEnd();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicPath);

        if ($this->madePackageJson) {
            File::delete(base_path('package.json'));
        }
        @unlink($this->environmentPath.'/.env');
        @rmdir($this->environmentPath);
        File::delete(array_diff(glob(Layouts::path().'/*.blade.php') ?: [], $this->layoutViews));

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    protected function installOptions(): array
    {
        return ['--no-interaction' => true, '--site-name' => 'Fjordkaffe', '--name' => 'Kari Nordmann', '--email' => 'kari@example.com', '--password' => 'secret-coffee'];
    }

    /**
     * A website with a front end to build, as one made from the starter app.
     */
    protected function withFrontEnd(): void
    {
        if (! is_file(base_path('package.json'))) {
            file_put_contents(base_path('package.json'), '{"private": true, "scripts": {"build": "vite build"}}');
            $this->madePackageJson = true;
        }
    }

    public function test_after_create_project_without_anyone_to_ask_it_says_how_to_finish(): void
    {
        $this->artisan('aurora:install', ['--interactive-only' => true, '--no-interaction' => true])
            ->expectsOutputToContain('php artisan aurora:install')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
    }

    public function test_with_build_it_installs_and_builds_the_website_s_front_end(): void
    {
        Process::fake(['npm --version' => Process::result('10.9.0'), '*' => Process::result()]);

        $this->artisan('aurora:install', [...$this->installOptions(), '--build' => true])
            ->expectsOutputToContain('Building the website\'s front end')
            ->assertSuccessful();

        Process::assertRan('npm install');
        Process::assertRan('npm run build');
    }

    public function test_without_npm_or_with_a_failing_build_the_install_still_finishes_and_says_what_to_run(): void
    {
        Process::fake(['npm --version' => Process::result(exitCode: 127)]);

        $this->artisan('aurora:install', [...$this->installOptions(), '--build' => true])
            ->expectsOutputToContain('npm wasn\'t found')
            ->assertSuccessful();

        Process::fake(['npm --version' => Process::result('10.9.0'), 'npm install' => Process::result(), 'npm run build' => Process::result(errorOutput: 'Could not resolve "./missing.css"', exitCode: 1)]);

        $this->artisan('aurora:install', [...$this->installOptions(), '--build' => true])
            ->expectsOutputToContain('npm run build failed')
            ->expectsOutputToContain('Could not resolve "./missing.css"')
            ->assertSuccessful();
    }

    public function test_after_create_project_it_says_to_go_into_the_new_folder(): void
    {
        $folder = basename(base_path());

        $this->artisan('aurora:install', [...$this->installOptions(), '--interactive-only' => true, '--no-interaction' => true])
            ->expectsOutputToContain("cd {$folder} && php artisan aurora:install")
            ->assertSuccessful();
    }

    public function test_a_blank_install_has_what_the_cms_needs_and_an_admin(): void
    {
        $this->artisan('aurora:install', $this->installOptions())->assertSuccessful();

        $admin = User::query()->where('email', 'kari@example.com')->sole();

        $this->assertTrue($admin->isSuperAdmin());
        $this->assertSame('Kari Nordmann', $admin->name);
        $this->assertSame(['en'], Locale::query()->pluck('code')->all());
        $this->assertSame('Fjordkaffe', Setting::get('site_name'));
        $this->assertGreaterThan(0, LanguageLine::query()->count());
        $this->assertSame(1, Client::query()->count());

        // No pages: the site starts empty.
        $this->assertFalse(Page::query()->exists());
    }

    public function test_installing_again_keeps_what_is_there(): void
    {
        $this->artisan('aurora:install', $this->installOptions())->assertSuccessful();
        Setting::put(['site_name' => 'Renamed']);

        $this->artisan('aurora:install', [...$this->installOptions(), '--password' => 'a-new-password'])->assertSuccessful();

        $this->assertSame(0, Page::query()->count());
        $this->assertSame(1, Client::query()->count());
        $this->assertSame(1, User::query()->count());
        $this->assertSame('Fjordkaffe', Setting::get('site_name'), 'The name given to the install wins.');
    }

    public function test_without_a_way_to_ask_the_admin_details_are_required(): void
    {
        $this->artisan('aurora:install', ['--no-interaction' => true])
            ->expectsOutputToContain('Pass --email and --password')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_an_interactive_install_asks_about_the_site_and_admin(): void
    {
        $this->artisan('aurora:install')
            ->expectsQuestion('What is the site called?', 'Fjordkaffe')
            ->expectsChoice('Which language is the website in?', 'nb', Locale::KNOWN)
            ->expectsChoice('Any other languages?', ['en'], array_diff_key(Locale::KNOWN, ['nb' => true]))
            ->expectsQuestion('Address prefix for English', 'english')
            ->expectsQuestion('Name', 'Kari Nordmann')
            ->expectsQuestion('Email address', 'kari@example.com')
            ->expectsQuestion('Password', 'secret-coffee')
            ->expectsConfirmation('Run the website\'s own seeders too?', 'no')
            ->expectsConfirmation('Build the website\'s front end now?', 'no')
            ->assertSuccessful();

        $this->assertSame(['nb', 'en'], Locale::query()->orderByDesc('is_default')->pluck('code')->all());
        $this->assertSame('nb', Locale::defaultCode());
        $this->assertSame('nb', User::query()->sole()->locale);

        // A page, in the site's language under / and English under /english.
        Page::factory()->homepage()->create(['title' => ['nb' => 'Velkommen til Fjordkaffe', 'en' => 'Welcome to Fjordkaffe']]);

        $this->get('/')->assertOk()->assertSee('Velkommen til Fjordkaffe')->assertSee('<html lang="nb"', false);
        $this->get('/english')->assertOk()->assertSee('<html lang="en"', false);
    }

    public function test_a_new_database_is_set_up_with_other_languages_before_its_tables_exist(): void
    {
        // As on a new database: no pages tables yet, and the migration that makes them still to run.
        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::drop('page_revisions');
            Schema::drop('pages');
        });
        DB::table('migrations')->where('migration', 'like', '%create_pages_table')->delete();

        $this->artisan('aurora:install')
            ->expectsQuestion('What is the site called?', 'Fjordkaffe')
            ->expectsChoice('Which language is the website in?', 'en', Locale::KNOWN)
            ->expectsChoice('Any other languages?', ['nb'], array_diff_key(Locale::KNOWN, ['en' => true]))
            ->expectsQuestion('Address prefix for Norsk bokmål', 'nb')
            ->expectsQuestion('Name', 'Kari Nordmann')
            ->expectsQuestion('Email address', 'kari@example.com')
            ->expectsQuestion('Password', 'secret-coffee')
            ->expectsConfirmation('Run the website\'s own seeders too?', 'no')
            ->expectsConfirmation('Build the website\'s front end now?', 'no')
            ->assertSuccessful();

        $this->assertTrue(Schema::hasTable('pages'));
        $this->assertSame(['en', 'nb'], Locale::query()->orderByDesc('is_default')->pluck('code')->all());
    }

    public function test_it_offers_to_start_over_when_the_database_already_has_content(): void
    {
        Page::factory()->count(3)->create();

        $this->artisan('aurora:install', ['--site-name' => 'Fjordkaffe', '--language' => 'en', '--languages' => 'nb:no', '--name' => 'Kari', '--email' => 'kari@example.com', '--password' => 'secret-coffee'])
            ->expectsConfirmation('The database already has content (3 pages). Start from scratch?', 'no')
            ->expectsConfirmation('Run the website\'s own seeders too?', 'no')
            ->expectsConfirmation('Build the website\'s front end now?', 'no')
            ->assertSuccessful();

        $this->assertSame(3, Page::query()->count(), 'Kept: nothing was dropped and no homepage added.');
        $this->assertSame('no', Locale::prefixFor('nb'));
    }

    public function test_a_language_may_live_under_its_own_code(): void
    {
        Locale::query()->updateOrCreate(['code' => 'en'], ['name' => 'English', 'is_default' => true, 'is_active' => true]);

        $this->artisan('aurora:install', [...$this->installOptions(), '--language' => 'nb', '--languages' => 'en:en'])
            ->assertSuccessful();

        $this->assertSame('en', Locale::prefixFor('en'));
    }

    public function test_starting_over_does_not_check_prefixes_against_what_is_being_dropped(): void
    {
        // A page at /english: kept, it blocks the prefix; starting over, it is going.
        Page::factory()->create(['slug' => 'english']);

        $this->artisan('aurora:install', [...$this->installOptions(), '--language' => 'nb', '--languages' => 'en:english'])
            ->expectsOutputToContain('A page already has this address.')
            ->assertFailed();

        // The database itself isn't dropped here (SQLite can't inside the test's transaction):
        // what matters is that the prefixes were checked before it would be.
        $this->app->extend(FreshCommand::class, fn (FreshCommand $command, $app): FreshCommand => new class($app['migrator']) extends FreshCommand
        {
            public function handle(): int
            {
                return self::SUCCESS;
            }
        });

        $this->artisan('aurora:install', [...$this->installOptions(), '--fresh' => true, '--force' => true, '--language' => 'nb', '--languages' => 'en:english'])
            ->assertSuccessful();

        $this->assertSame('english', Locale::prefixFor('en'));
    }

    public function test_the_default_site_is_named_after_the_website_and_has_no_domain(): void
    {
        $this->artisan('aurora:install', $this->installOptions())->assertSuccessful();

        $site = Site::default();
        $this->assertSame('Fjordkaffe', $site->name);
        $this->assertNull($site->domain);
    }

    public function test_the_install_indexes_the_pages_for_search(): void
    {
        $page = Page::factory()->published()->create(['title' => 'Fjord cruises']);
        $page->forceFill(['search_text' => null])->saveQuietly();

        $this->artisan('aurora:install', $this->installOptions())
            ->expectsOutputToContain('Indexing pages for search')
            ->assertSuccessful();

        $this->assertStringContainsString('Fjord cruises', (string) $page->fresh()->search_text);
        $this->assertSame([$page->id], Page::search('cruises')->get()->modelKeys());
    }
}
