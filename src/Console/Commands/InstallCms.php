<?php

namespace Aurora\Console\Commands;

use Aurora\Aurora;
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
    {--fresh : Drop every table and start over}
    {--force : Don\'t ask before dropping tables}
    {--site-name= : The site\'s name}
    {--language= : The website\'s language, as a code such as en or nb}
    {--languages= : Other languages to translate the website into, comma separated, each with an optional address prefix (e.g. nb:no,sv)}
    {--name= : The admin\'s name}
    {--email= : The admin\'s email address}
    {--password= : The admin\'s password}
    {--seed : Then run the website\'s own seeders (database/seeders/DatabaseSeeder) without asking}
    {--interactive-only : Only set up when someone can answer the questions (as after composer create-project); otherwise say how to finish}')]
#[Description('Set up the CMS: app key, database, the content it needs, labels and an admin account')]
class InstallCms extends Command
{
    /**
     * The website's own seeder, by Laravel's convention: what it seeds is the website's.
     */
    public const WEBSITE_SEEDER = 'Database\\Seeders\\DatabaseSeeder';

    public function handle(TranslationSync $sync): int
    {
        // Run by composer create-project as its last step: nobody there to answer (with
        // --no-interaction, or in CI) is no reason to fail the whole create-project.
        if ($this->option('interactive-only') && ! $this->canAsk()) {
            $this->components->info('Finish setting up the CMS with: php artisan aurora:install');

            return self::SUCCESS;
        }

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

        $siteName = $this->siteName();
        $language = $this->language();

        // Prefixes are checked against the languages and pages already there, when there are any:
        // a new database has no tables yet, and starting over they're going.
        $otherLanguages = $language === false ? false : $this->otherLanguages($language, againstExisting: ! $fresh && $this->hasTable('pages'));

        if ($language === false || $otherLanguages === false) {
            return self::FAILURE;
        }
        $admin = $this->adminDetails();

        if ($admin === false) {
            return self::FAILURE;
        }

        $seedWebsite = $this->seedsWebsite();

        if (! file_exists($this->laravel->environmentFilePath()) && file_exists(base_path('.env.example'))) {
            $this->components->task('Creating .env from .env.example', fn () => copy(base_path('.env.example'), $this->laravel->environmentFilePath()));
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

        $this->components->task('Seeding what the CMS needs', function () use ($siteName, $language, $otherLanguages): bool {
            // The chosen language first: the essential seeder only adds English to a site without one.
            Locale::query()->updateOrCreate(['code' => $language], ['name' => Locale::KNOWN[$language], 'is_default' => true, 'is_active' => true, 'sort' => 1]);

            foreach (array_keys($otherLanguages) as $index => $code) {
                Locale::query()->updateOrCreate(['code' => $code], ['name' => Locale::KNOWN[$code], 'prefix' => $otherLanguages[$code], 'is_active' => true, 'sort' => $index + 2]);
            }

            // No pages: the site starts empty. A website's own content is its own seeders'.
            $result = $this->seed(EssentialSeeder::class);
            Setting::put(['site_name' => $siteName]);

            return $result;
        });

        // The default site (no domain: it answers on every address) is named after the website.
        CurrentSite::default()?->update(['name' => $siteName]);

        // Every page in search from the start (those already there), on every site.
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

        // The website's own content (its homepage, say), once the CMS and its admin are there.
        if ($seedWebsite) {
            $this->components->task('Seeding the website\'s content', fn () => $this->seed(self::WEBSITE_SEEDER));
        }

        $this->newLine();
        $this->components->info('The CMS is ready. Sign in at '.Filament::getPanel('admin')->getLoginUrl());

        return self::SUCCESS;
    }

    /**
     * Whether there's someone to answer the questions: an interactive run in a terminal.
     * Composer can call a script interactively without one (create-project in CI), and
     * then the prompts can't ask.
     */
    protected function canAsk(): bool
    {
        return $this->input->isInteractive() && defined('STDIN') && stream_isatty(STDIN);
    }

    /**
     * Pages already in the database (0 before the first migration).
     */
    protected function existingPageCount(): int
    {
        try {
            return $this->hasTable('pages') ? Page::withTrashed()->count() : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Whether to run the website's own seeders after setting up the CMS: with --seed, or when
     * asked (yes unless said otherwise). Never without a DatabaseSeeder to run.
     */
    protected function seedsWebsite(): bool
    {
        if (! class_exists(self::WEBSITE_SEEDER)) {
            return false;
        }

        return $this->option('seed') || ($this->input->isInteractive() && confirm(
            'Run the website\'s own seeders too?',
            default: true,
            hint: 'database/seeders/DatabaseSeeder: the website\'s first content, such as its homepage.',
        ));
    }

    /**
     * Whether the database has a table yet (false before the first migration, or without a
     * database to reach).
     */
    protected function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
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
     * The admin account to create (or update, by email): false when the details are missing
     * without a way to ask for them.
     *
     * @return array{name: string, email: string, password: string}|false
     */
    protected function adminDetails(): array|false
    {
        $given = array_filter(['name' => $this->option('name'), 'email' => $this->option('email'), 'password' => $this->option('password')]);

        if (! $this->input->isInteractive()) {
            if (isset($given['email'], $given['password'])) {
                return ['name' => $given['name'] ?? 'Admin', 'email' => $given['email'], 'password' => $given['password']];
            }

            $this->components->error('Pass --email and --password (and --name) to create the admin account.');

            return false;
        }

        $this->components->info('The admin account (a Super Admin)');

        return [
            'name' => $given['name'] ?? text('Name', required: true),
            'email' => $given['email'] ?? text('Email address', required: true, validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Enter a valid email address.'),
            'password' => $given['password'] ?? password('Password', required: true, validate: fn (string $value): ?string => mb_strlen($value) < 8 ? 'Use at least 8 characters.' : null),
        ];
    }
}
