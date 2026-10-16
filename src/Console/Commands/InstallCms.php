<?php

namespace Aurora\Console\Commands;

use Aurora\Aurora;
use Aurora\Database\Seeders\Demo\DemoSeeder;
use Aurora\Database\Seeders\EssentialSeeder;
use Aurora\Models\Locale;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\CurrentSite;
use Aurora\Support\TranslationSync;
use Filament\Facades\Filament;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('aurora:install
    {--demo : Seed the demo site (pages, media, forms, menus, demo accounts and Norwegian)}
    {--demo-login : With the demo: turn on demo mode (one-click sign-in as the demo accounts) in .env}
    {--fresh : Drop every table and start over}
    {--force : Don\'t ask before dropping tables}
    {--site-name= : The site\'s name}
    {--language= : The website\'s language, as a code such as en or nb}
    {--languages= : Other languages to translate the website into, comma separated, each with an optional address prefix (e.g. nb:no,sv)}
    {--name= : The admin\'s name}
    {--email= : The admin\'s email address}
    {--password= : The admin\'s password}')]
#[Description('Set up the CMS: app key, database, the content it needs (or the demo), labels and an admin account')]
class InstallCms extends Command
{
    public function handle(TranslationSync $sync): int
    {
        // The demo content and demo mode only when asked for with their flags: never offered.
        $demo = (bool) $this->option('demo');
        $demoLogin = $demo && $this->option('demo-login');

        $fresh = (bool) $this->option('fresh');

        if ($fresh && ! $this->option('force') && ! confirm('Drop every table and start over? Everything in the database is deleted.', default: false)) {
            return self::FAILURE;
        }

        // An install keeps what the database already has (and only adds what's missing), so
        // say so when there is content: it may be from an earlier install.
        if (! $fresh && ($pages = $this->existingPageCount()) > 0 && $this->input->isInteractive()) {
            $fresh = confirm(
                "The database already has content ({$pages} pages). Start from scratch?",
                default: false,
                hint: 'Yes deletes everything in the database first. No keeps it and only adds what is missing.',
            );
        }

        $siteName = $demo ? null : $this->siteName();
        $language = $demo ? null : $this->language();

        $otherLanguages = $demo ? [] : $this->otherLanguages((string) $language, againstExisting: ! $fresh);

        if ($language === false || $otherLanguages === false) {
            return self::FAILURE;
        }
        $admin = $this->adminDetails($demo);

        if ($admin === false) {
            return self::FAILURE;
        }

        if (! file_exists($this->laravel->environmentFilePath()) && file_exists(base_path('.env.example'))) {
            $this->components->task('Creating .env from .env.example', fn () => copy(base_path('.env.example'), $this->laravel->environmentFilePath()));
        }

        // Demo mode only when asked for this install: one left from an earlier demo goes.
        if ($demoLogin) {
            $this->components->task('Turning on demo mode in .env', fn () => $this->setEnvironmentValue('AURORA_DEMO_LOGIN', 'true'));
        } elseif ($this->hasEnvironmentValue('AURORA_DEMO_LOGIN')) {
            $this->components->task('Turning off demo mode in .env', fn () => $this->removeEnvironmentValue('AURORA_DEMO_LOGIN'));
        }

        if (blank(config('app.key'))) {
            $this->components->task('Generating the application key', fn () => $this->callSilently('key:generate', ['--force' => true]) === self::SUCCESS);
        }

        $this->components->task(
            $fresh ? 'Rebuilding the database' : 'Migrating the database',
            fn () => $this->callSilently($fresh ? 'migrate:fresh' : 'migrate', ['--force' => true]) === self::SUCCESS,
        );

        // A rebuilt database has a new default site.
        CurrentSite::forget();

        // The admin's prebuilt theme and scripts, copied to public/.
        $this->components->task('Publishing the admin\'s theme and scripts', fn () => $this->callSilently('filament:assets') === self::SUCCESS);

        // The page layouts' views, to make the website's own (a site's edited copies are kept).
        $this->components->task('Publishing the page layouts', fn () => $this->callSilently('vendor:publish', ['--tag' => 'aurora-layouts']) === self::SUCCESS);

        if ($demo && Page::withTrashed()->exists()) {
            $this->components->warn('The site already has pages: skipping the demo content. Use --fresh to start over.');
            $demo = false;
            $this->components->task('Seeding what the CMS needs', fn () => $this->seed(EssentialSeeder::class));
        } elseif ($demo) {
            $this->components->task('Seeding the demo site', fn () => $this->seed(DemoSeeder::class));
        } else {
            $this->components->task('Seeding what the CMS needs', function () use ($siteName, $language, $otherLanguages): bool {
                // The chosen language first: the essential seeder only adds English to a site without one.
                Locale::query()->updateOrCreate(['code' => $language], ['name' => Locale::KNOWN[$language], 'is_default' => true, 'is_active' => true, 'sort' => 1]);

                foreach (array_keys($otherLanguages) as $index => $code) {
                    Locale::query()->updateOrCreate(['code' => $code], ['name' => Locale::KNOWN[$code], 'prefix' => $otherLanguages[$code], 'is_active' => true, 'sort' => $index + 2]);
                }

                // No pages: the site starts empty (the demo content is --demo).
                $result = $this->seed(EssentialSeeder::class);
                Setting::put(['site_name' => $siteName]);

                return $result;
            });
        }

        // The default site (no domain: it answers on every address) is named after the website.
        CurrentSite::default()?->update(['name' => $siteName ?? Setting::get('site_name')]);

        // Every page in search from the start (the demo's, or those already there), on every site.
        $this->components->task('Indexing pages for search', function (): bool {
            Page::query()->withoutGlobalScopes()->whereNull('deleted_at')->each(fn (Page $page) => $page->reindex());

            return true;
        });

        $this->components->task('Syncing labels from the lang files', function () use ($sync): bool {
            $sync->import();

            return true;
        });

        // The keys that sign AI apps' access tokens (not in the repository).
        if (! file_exists(storage_path('oauth-private.key'))) {
            $this->components->task('Creating the keys for AI app tokens', fn () => $this->callSilently('passport:keys') === self::SUCCESS);
        }

        if (! file_exists(public_path('storage'))) {
            $this->components->task('Linking public storage', fn () => $this->callSilently('storage:link') === self::SUCCESS);
        }

        if ($admin !== null) {
            $this->components->task("Creating the admin account for {$admin['email']}", function () use ($admin, $language): bool {
                $user = User::query()->updateOrCreate(['email' => $admin['email']], array_filter([
                    'name' => $admin['name'],
                    'password' => $admin['password'],
                    'is_active' => true,
                    // The admin panel in the website's language, when the panel has it.
                    'locale' => $language && collect(Aurora::langPaths())->contains(fn (string $lang): bool => is_file("{$lang}/{$language}.json")) ? $language : null,
                ], fn (mixed $value): bool => $value !== null));

                $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
                $user->syncRoles([User::SUPER_ADMIN]);

                return true;
            });
        }

        $this->newLine();
        $this->components->info('The CMS is ready. Sign in at '.Filament::getPanel('admin')->getLoginUrl());

        if ($demo) {
            $this->components->bulletList(array_filter([
                'Demo accounts: admin@, editor@, author@ and viewer@example.com, all with the password "password".',
                $demoLogin ? 'Demo mode is on: the login page signs in as any of them with one click.' : null,
            ]));
        }

        return self::SUCCESS;
    }

    /**
     * Set a value in .env: changed where the key is, added where it isn't. Cached
     * configuration is cleared so the app reads it.
     */
    protected function setEnvironmentValue(string $key, string $value): bool
    {
        $path = $this->laravel->environmentFilePath();
        $contents = file_exists($path) ? (string) file_get_contents($path) : '';
        $line = "{$key}={$value}";

        $contents = preg_match("/^{$key}=.*$/m", $contents)
            ? preg_replace("/^{$key}=.*$/m", $line, $contents)
            : rtrim($contents, "\n").($contents === '' ? '' : "\n\n").$line."\n";

        file_put_contents($path, $contents);

        if ($this->laravel->configurationIsCached()) {
            $this->callSilently('config:clear');
        }

        return true;
    }

    protected function hasEnvironmentValue(string $key): bool
    {
        $path = $this->laravel->environmentFilePath();

        return file_exists($path) && preg_match("/^{$key}=/m", (string) file_get_contents($path)) === 1;
    }

    /**
     * Take a value out of .env (with the blank line setEnvironmentValue() put before it), and
     * clear cached configuration so the app stops reading it.
     */
    protected function removeEnvironmentValue(string $key): bool
    {
        $path = $this->laravel->environmentFilePath();
        $contents = (string) preg_replace('/^'.preg_quote($key, '/').'=.*(?:\r?\n|$)/m', '', (string) file_get_contents($path));

        file_put_contents($path, rtrim((string) preg_replace('/\n{3,}/', "\n\n", $contents), "\n")."\n");

        if ($this->laravel->configurationIsCached()) {
            $this->callSilently('config:clear');
        }

        return true;
    }

    /**
     * Pages already in the database (0 before the first migration).
     */
    protected function existingPageCount(): int
    {
        try {
            return Schema::hasTable('pages') ? Page::withTrashed()->count() : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    protected function seed(string $seeder): bool
    {
        return $this->callSilently('db:seed', ['--class' => $seeder, '--force' => true]) === self::SUCCESS;
    }

    /**
     * The website's language code, from the known languages: false for one that isn't known.
     */
    protected function language(): string|false
    {
        $given = $this->option('language');

        if (filled($given)) {
            if (! array_key_exists($given, Locale::KNOWN)) {
                $this->components->error("Unknown language \"{$given}\". Use one of: ".implode(', ', array_keys(Locale::KNOWN)).'.');

                return false;
            }

            return $given;
        }

        return $this->input->isInteractive()
            ? (string) select('Which language is the website in?', Locale::KNOWN, default: 'en', scroll: 10, hint: 'The default language. More can be added under Settings › Languages.')
            : 'en';
    }

    /**
     * Languages the website is translated into besides its own, with the address prefix each
     * lives under (its code unless another is given): false for an unknown or unusable one.
     * Starting over, the languages and pages in the database don't count: they are going.
     *
     * @return array<string, string>|false code => prefix
     */
    protected function otherLanguages(string $language, bool $againstExisting = true): array|false
    {
        $options = array_diff_key(Locale::KNOWN, [$language => true]);
        $defaultPrefix = fn (string $code): string => strtolower(str_replace('_', '-', $code));

        if (filled($given = $this->option('languages'))) {
            $languages = [];

            foreach (array_filter(array_map('trim', explode(',', $given))) as $entry) {
                [$code, $prefix] = array_pad(explode(':', $entry, 2), 2, null);

                if (! array_key_exists($code, $options)) {
                    $this->components->error("Unknown language \"{$code}\". Use any of: ".implode(', ', array_keys($options)).'.');

                    return false;
                }

                $prefix = strtolower($prefix ?: $defaultPrefix($code));

                if (($problem = Locale::prefixProblem($prefix, $code, $againstExisting) ?? (in_array($prefix, $languages, true) ? __('Another language already uses this prefix.') : null)) !== null) {
                    $this->components->error("The prefix \"{$prefix}\" for {$code}: {$problem}");

                    return false;
                }

                $languages[$code] = $prefix;
            }

            return $languages;
        }

        if (! $this->input->isInteractive()) {
            return [];
        }

        $languages = [];

        foreach (multiselect('Any other languages?', $options, scroll: 10, hint: 'Languages to translate the website into. Space to pick, enter to go on; none is fine.') as $code) {
            $languages[$code] = text(
                "Address prefix for {$options[$code]}",
                default: $defaultPrefix($code),
                required: true,
                validate: fn (string $value): ?string => Locale::prefixProblem(strtolower($value), $code, $againstExisting) ?? (in_array(strtolower($value), $languages, true) ? __('Another language already uses this prefix.') : null),
                hint: "Its addresses start with it, as in /{$defaultPrefix($code)}/about-us. The language code stays {$code}.",
            );
        }

        return array_map('strtolower', $languages);
    }

    protected function siteName(): string
    {
        $default = (string) config('app.name');

        if (filled($this->option('site-name'))) {
            return (string) $this->option('site-name');
        }

        return $this->input->isInteractive() ? text('What is the site called?', default: $default, required: true) : $default;
    }

    /**
     * The admin account to create (or update, by email): null for none (the demo has its own
     * accounts), false when the details are missing without a way to ask for them.
     *
     * @return array{name: string, email: string, password: string}|false|null
     */
    protected function adminDetails(bool $demo): array|false|null
    {
        $given = array_filter(['name' => $this->option('name'), 'email' => $this->option('email'), 'password' => $this->option('password')]);

        if (! $this->input->isInteractive()) {
            if (isset($given['email'], $given['password'])) {
                return ['name' => $given['name'] ?? 'Admin', 'email' => $given['email'], 'password' => $given['password']];
            }

            if ($demo) {
                return null;
            }

            $this->components->error('Pass --email and --password (and --name) to create the admin account.');

            return false;
        }

        if ($demo && ! isset($given['email']) && ! confirm('Create your own admin account too? The demo has its own accounts.', default: true)) {
            return null;
        }

        $this->components->info('The admin account (a Super Admin)');

        return [
            'name' => $given['name'] ?? text('Name', required: true),
            'email' => $given['email'] ?? text('Email address', required: true, validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Enter a valid email address.'),
            'password' => $given['password'] ?? password('Password', required: true, validate: fn (string $value): ?string => mb_strlen($value) < 8 ? 'Use at least 8 characters.' : null),
        ];
    }
}
