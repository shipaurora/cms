<?php

namespace Aurora\Providers;

use Aurora\Aurora;
use Aurora\Blocks\Blocks;
use Aurora\Console\Commands\ExportTranslations;
use Aurora\Console\Commands\ImportTranslations;
use Aurora\Console\Commands\ImportWordPress;
use Aurora\Console\Commands\InstallCms;
use Aurora\Console\Commands\MakeBlock;
use Aurora\Console\Commands\SyncTranslations;
use Aurora\Enums\AssetType;
use Aurora\Events\ContentEvent;
use Aurora\Filament\Forms\Components\RichTextLinkAction;
use Aurora\Filament\Forms\Components\WriteWithAi;
use Aurora\Filament\Resources\Roles\RoleResource;
use Aurora\Http\Middleware\ResolveSite;
use Aurora\Http\Middleware\SetCopilotSite;
use Aurora\Http\Middleware\UseSiteLocale;
use Aurora\Livewire\AssistantChat;
use Aurora\Livewire\EditLock;
use Aurora\Livewire\ManagePasskeys;
use Aurora\Livewire\MenuItems;
use Aurora\Livewire\PasskeyPrompt;
use Aurora\Models\Asset;
use Aurora\Models\Form;
use Aurora\Models\ImageFormat;
use Aurora\Models\LanguageLine;
use Aurora\Models\Layout;
use Aurora\Models\Locale;
use Aurora\Models\Media;
use Aurora\Models\Menu;
use Aurora\Models\MenuItem;
use Aurora\Models\MenuLocation;
use Aurora\Models\NavigationLocation;
use Aurora\Models\Page;
use Aurora\Models\Passkey;
use Aurora\Models\Permission;
use Aurora\Models\Redirect;
use Aurora\Models\Role;
use Aurora\Models\Setting;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\ActivityLog;
use Aurora\Support\AdminIcons;
use Aurora\Support\AssistantAgent;
use Aurora\Support\ContentLocale;
use Aurora\Support\Copilot;
use Aurora\Support\CopilotContext;
use Aurora\Support\CopilotToolRegistry;
use Aurora\Support\CurrentSite;
use Aurora\Support\Layouts;
use Aurora\Support\MissingLabels;
use Aurora\Support\PageCache;
use Aurora\Support\Search\DatabaseEngine as SearchDatabaseEngine;
use Aurora\Support\SiteIcon;
use Aurora\Support\TranslationLoader;
use Aurora\Support\Webhooks;
use BladeUI\Icons\Factory as IconFactory;
use EslamRedaDiv\FilamentCopilot\Agent\ContextBuilder;
use EslamRedaDiv\FilamentCopilot\Agent\CopilotAgent;
use EslamRedaDiv\FilamentCopilot\Services\ToolRegistry;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\RichEditor;
use Filament\Infolists\Components\Entry;
use Filament\Notifications\Livewire\Notifications;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Filament\View\PanelsIconAlias;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Laravel\Scout\EngineManager;
use Livewire\Livewire;
use Spatie\Translatable\Facades\Translatable;

use function Livewire\on;

/**
 * Aurora's own set-up: what the CMS needs from Laravel, Filament and the packages it builds
 * on. The admin panel itself is AdminPanelProvider; the website's set-up stays in the app.
 */
class AuroraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Aurora's settings (config/aurora.php), which an app may publish and change.
        $this->mergeConfigFrom(__DIR__.'/../../config/aurora.php', 'aurora');

        // Once every package has loaded its config: Aurora's models and choices for them.
        $this->app->booting(fn () => $this->configurePackages());

        // The in-panel copilot: CMS-specific instructions and screen awareness, and only the
        // CMS tools plus memory (see Aurora\Support\CopilotContext and CopilotToolRegistry).
        $this->app->bind(ContextBuilder::class, CopilotContext::class);
        $this->app->bind(CopilotAgent::class, AssistantAgent::class);

        // The admin panel draws Phosphor icons wherever Heroicons are asked for; the website
        // keeps the real Heroicons as "hero-" icons (see Aurora\Support\AdminIcons and SiteIcon).
        if (AdminIcons::ensureBuilt()) {
            config(['blade-heroicons.path' => AdminIcons::directory(), 'blade-heroicons.attributes.aria-hidden' => 'true']);
        }

        $this->callAfterResolving(IconFactory::class, fn (IconFactory $factory) => SiteIcon::register($factory));

        // The media library takes files up to 50 MB (videos, PDFs); Livewire's own limit is 12 MB.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.AssetType::MAX_FILE_KB]]);
        $this->app->singleton(ToolRegistry::class, CopilotToolRegistry::class);

        // Labels from the database on top of the lang files (spatie/laravel-translation-loader,
        // via Aurora\Support\TranslationLoader). The package's own binding only reads lang/,
        // which loses Laravel's built-in lang files (validation messages and the like). The
        // package's provider is deferred and rebinds the loader when first used, so this
        // extends the binding rather than replacing it.
        $this->app->extend('translation.loader', fn ($loader, $app) => new TranslationLoader(
            $app['files'],
            [base_path('vendor/laravel/framework/src/Illuminate/Translation/lang'), Aurora::path('lang'), $app['path.lang']],
        ));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/ai.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        $this->registerWebsite();
        $this->registerBladeDirectives();
        $this->clearPageCacheOnChanges();

        // Who changed what (Administration › Activity log).
        ActivityLog::listen();

        // What happens to the content, sent to the webhooks that want it.
        Event::listen(Webhooks::EVENTS, fn (ContentEvent $event) => Webhooks::send($event));

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/aurora.php' => config_path('aurora.php')], 'aurora-config');
            // aurora:make-block's templates, to shape new blocks after the site's own.
            $this->publishes([__DIR__.'/../../stubs' => base_path('stubs/aurora')], 'aurora-stubs');
            // The website-facing views a site makes its own: the page layouts (published on
            // install) and the password page.
            $this->publishes([__DIR__.'/../../resources/views/layouts' => Layouts::path()], 'aurora-layouts');
            $this->publishes(collect(['page-password', 'page-sign-in', 'site-closed'])->mapWithKeys(fn (string $view): array => [__DIR__."/../../resources/views/{$view}.blade.php" => resource_path("views/vendor/aurora/{$view}.blade.php")])->all(), 'aurora-views');
            $this->commands([InstallCms::class, MakeBlock::class, ImportTranslations::class, ExportTranslations::class, SyncTranslations::class, ImportWordPress::class]);
        }

        // Livewire components used by tag (<livewire:…>); the app's own namespace is found by itself.
        Livewire::component('manage-passkeys', ManagePasskeys::class);
        Livewire::component('passkey-prompt', PasskeyPrompt::class);
        Livewire::component('edit-lock', EditLock::class);

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        // Aurora's prebuilt admin scripts (npm run build:aurora), published by filament:assets.
        // Its admin theme comes from aurora/aurora.
        FilamentAsset::register([
            // The image editor's styles (Cropper.js), which come with the scripts.
            Css::make('image-editor', Aurora::path('resources/dist/aurora.css')),
            Js::make('aurora', Aurora::path('resources/dist/aurora.js'))->module()->loadedOnRequest(),
        ], package: 'aurora/cms');
        $this->registerViews();

        // Aurora's models and their factories (Aurora\Database\Factories) find each other.
        Factory::guessFactoryNamesUsing(fn (string $model): string => 'Aurora\\Database\\Factories\\'.class_basename($model).'Factory');
        Factory::guessModelNamesUsing(fn (Factory $factory): string => 'Aurora\\Models\\'.Str::replaceLast('Factory', '', class_basename($factory)));

        FilamentIcon::register([
            PanelsIconAlias::TENANT_MENU_TOGGLE_BUTTON => 'phosphor-caret-up-down',
            PanelsIconAlias::TOPBAR_OPEN_DATABASE_NOTIFICATIONS_BUTTON => 'phosphor-bell',
        ]);

        // Just signed in: the next admin page may offer to add a passkey (PasskeyPrompt).
        // Offer a passkey after signing in, but not the first time: a first visit to the admin
        // has enough going on.
        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            if ($event->user->last_signed_in_at !== null) {
                session()->put(PasskeyPrompt::SESSION_KEY, true);
            }

            $event->user->forceFill(['last_signed_in_at' => now()])->saveQuietly();
        });

        // migrate:fresh recreates the default site: don't keep handing out the old one.
        Event::listen(MigrationsEnded::class, fn () => CurrentSite::forget());

        // Provider, model and API key come from Site settings, not .env.
        $this->app->booted(fn () => Copilot::applyConfig());

        // The copilot package loads its routes from a booted callback, after this provider's,
        // so the stream route does not exist yet to attach middleware to. Add it when the
        // route is matched, before that middleware runs: the stream sits outside the admin's
        // tenant routes and otherwise saves the chat with no site.
        Event::listen(RouteMatched::class, function (RouteMatched $event): void {
            if ($event->route->getName() !== 'filament-copilot.stream') {
                return;
            }

            if (! in_array(SetCopilotSite::class, $event->route->middleware(), true)) {
                $event->route->middleware(SetCopilotSite::class);
            }
        });

        // Scout's database engine, with full-text search on MySQL (Aurora\Support\Search\DatabaseEngine).
        $this->app->resolving(EngineManager::class, fn (EngineManager $engines) => $engines->extend('database', fn () => new SearchDatabaseEngine));

        // The site's blocks in aurora.block_path, after those it registers itself (whose order
        // in the palette they keep).
        $this->app->booted(function (): void {
            if (filled($path = config('aurora.block_path'))) {
                Blocks::discover($path, config('aurora.block_namespace'));
            }
        });

        // Aurora's own versions of packages' Livewire components: registered once every
        // provider has booted, since those packages register theirs when they boot (after
        // Aurora's provider), which would replace these.
        $this->app->booted(function (): void {
            // The assistant's chat resumes the open conversation after a reload.
            Livewire::component('filament-copilot-chat', AssistantChat::class);

            // Menu items edit in a dialog, and pick their icon from the same picker as blocks.
            Livewire::component('menu-builder-items', MenuItems::class);
        });

        // Every label is translatable: Filament's labels (fields, columns, filters, actions)
        // go through the translator. A sentence label without a translation in the current
        // language shows its default-language text (Aurora\Support\MissingLabels).
        Action::configureUsing(fn (Action $action) => $action->translateLabel());
        Field::configureUsing(fn (Field $field) => $field->translateLabel());
        Entry::configureUsing(fn (Entry $entry) => $entry->translateLabel());
        Column::configureUsing(fn (Column $column) => $column->translateLabel());
        BaseFilter::configureUsing(fn (BaseFilter $filter) => $filter->translateLabel());
        app('translator')->handleMissingKeysUsing(MissingLabels::handle(...));

        // The visual editor's save status gives its span two class attributes, and browsers
        // drop the second, so "not saved" never got its class. Merge them as it compiles; if
        // the package changes these lines, this does nothing.
        // The visual editor's language: the switcher leads the toolbar's actions (like Filament's
        // own language switcher), what is left to translate sits by the page title.
        Blade::prepareStringsForCompilationUsing(fn (string $view): string => str_replace(
            [
                '<span class="fpb-status" @class([\'fpb-status-dirty\' => $this->isDirty])>',
                '<h1 class="fpb-chrome-title">{{ $this->getRecordTitle() }}</h1>',
                "{{ __('page-builder::chrome.save_layout') }}",
                '<div class="fpb-toolbar-actions">',
            ],
            [
                '<span @class([\'fpb-status\', \'fpb-status-dirty\' => $this->isDirty])>',
                '<h1 class="fpb-chrome-title">{{ $this->getRecordTitle() }}</h1>@includeWhen(method_exists($this, \'untranslatedBlockIds\'), \'aurora::filament.pages.editor-translation-status\')',
                "<span class=\"cms-save-long\">{{ method_exists(\$this, 'isTranslating') && \$this->isTranslating() ? __('Save translation') : __('page-builder::chrome.save_layout') }}</span><span class=\"cms-save-short\">{{ __('Save') }}</span>",
                '<div class="fpb-toolbar-actions">@includeWhen(method_exists($this, \'untranslatedBlockIds\'), \'aurora::filament.pages.editor-language\')',
            ],
            $view,
        ));

        // Content without a translation shows the default site language's (pages' titles,
        // addresses, summaries and SEO text; block text falls back the same way).
        $this->app->booted(fn () => Translatable::fallback(fallbackLocale: rescue(fn (): string => ContentLocale::default(), config('app.fallback_locale'), report: false), fallbackAny: true));

        // Notifications rise from the bottom right, out of the way of the page header's buttons.
        Notifications::alignment(Alignment::End);
        Notifications::verticalAlignment(VerticalAlignment::End);

        // Delete looks like the other buttons (a red label, not a red block) and says what it does.
        DeleteAction::configureUsing(fn (DeleteAction $action) => $action->icon('phosphor-trash'));

        // The filter button's count only when a filter is on: Filament sets the badge itself on
        // every render, "0" included, so the count rides along and the stylesheet hides a zero.
        Table::configureUsing(fn (Table $table) => $table->filtersTriggerAction(fn (Action $action) => $action->extraAttributes(fn (): array => [
            'data-active-filters' => $table->getActiveFiltersCount(),
        ])));

        // Deleting, restoring and deleting for good change what's in the trash: the sidebar's
        // Trash entry (shown only with something in it) and its count follow straight away.
        foreach ([DeleteAction::class, DeleteBulkAction::class, RestoreAction::class, RestoreBulkAction::class, ForceDeleteAction::class, ForceDeleteBulkAction::class] as $action) {
            $action::configureUsing(fn (Action $action) => $action->after(fn ($livewire) => $livewire->dispatch('refresh-sidebar')));
        }

        // ->withAi() on text inputs and textareas: "Write with AI" next to the label.
        WriteWithAi::register();
        WriteWithAi::registerColumn();

        // Rich text links can point at a page or a media library file, not just a URL.
        RichEditor::configureUsing(fn (RichEditor $editor) => $editor->registerActions([RichTextLinkAction::make()]));

        // "3 images selected" instead of Filament's "3 records selected": each table says
        // what it lists. The wording is a translatable label like any other.
        on('render', function (object $component): void {
            if (! $component instanceof HasTable) {
                return;
            }

            try {
                $table = $component->getTable();
                $singular = __(Str::lower((string) $table->getModelLabel()));
                $plural = __(Str::lower((string) $table->getPluralModelLabel()));
            } catch (\Throwable) {
                return;
            }

            // Load Filament's table labels first: lines added to a group that isn't loaded yet
            // count as the whole group, and every other table label would fall back to English.
            app('translator')->load('filament-tables', 'table', app()->getLocale());

            app('translator')->addLines([
                'table.selection_indicator.selected_count' => __('1 :label selected|:count :labels selected', ['label' => $singular, 'labels' => $plural]),
            ], app()->getLocale(), 'filament-tables');
        });

        // OAuth for MCP clients: the consent screen is a Filament-styled page, access tokens
        // are short-lived and refreshed by the client, personal tokens last a year.
        Passport::authorizationView('aurora::oauth.authorize');
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(90));
        Passport::personalAccessTokensExpireIn(now()->addYear());

        // Super Admins may do everything, except what the User and Role policies forbid even
        // them: deleting or deactivating themselves, and deleting or renaming the Super Admin role.
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $subject = $arguments[0] ?? null;

            if ($subject instanceof User || $subject instanceof Role) {
                return null;
            }

            return $user->isSuperAdmin() ? true : null;
        });
    }

    /**
     * The block view helpers (src/helpers.php) as Blade directives: @inlineText($data['title'])
     * prints what {{ inline_text($data['title']) }} does, and @editing … @endediting and
     *
     * @shows('body', $data['body'] ?? null) … @endshows wrap what is_editing() and
     * shows_field() decide. Not @slot: Blade's components already use it.
     */
    protected function registerBladeDirectives(): void
    {
        $echoes = [
            'inlineText' => 'inline_text',
            'plainText' => 'plain_text',
            'richText' => 'rich_text',
            'linkHref' => 'link_href',
            'linkTarget' => 'link_target',
            'blockSlot' => 'block_slot',
        ];

        foreach ($echoes as $directive => $helper) {
            Blade::directive($directive, fn (string $expression): string => "<?php echo e({$helper}({$expression})); ?>");
        }

        Blade::if('editing', fn (): bool => is_editing());
        Blade::if('shows', fn (string $field, mixed $value): bool => shows_field($field, $value));
    }

    /**
     * Aurora's views as aurora::…, and its versions of other packages' views (Filament's
     * screens, the mail layout …) ahead of those packages' own. An app's own copy in
     * resources/views/vendor still wins over both.
     */
    protected function registerViews(): void
    {
        $views = __DIR__.'/../../resources/views';

        $this->loadViewsFrom($views, 'aurora');

        $this->app->booted(function () use ($views): void {
            foreach (File::directories($views.'/vendor') as $directory) {
                $namespace = basename($directory);

                View::prependNamespace($namespace, $directory);

                if (is_dir($ownCopy = resource_path("views/vendor/{$namespace}"))) {
                    View::prependNamespace($namespace, $ownCopy);
                }
            }
        });

        // Mails in Aurora's look (vendor/mail/html/themes/aurora.css), unless the app picked a theme.
        config([
            'mail.markdown.paths' => [...config('mail.markdown.paths', [resource_path('views/vendor/mail')]), $views.'/vendor/mail'],
            'mail.markdown.theme' => in_array(config('mail.markdown.theme'), [null, 'default'], true) ? 'aurora' : config('mail.markdown.theme'),
        ]);
    }

    /**
     * What Aurora needs from the packages it builds on, whatever the app's config says: its
     * user model (Aurora owns it; the app's may extend it), its role, media, passkey and
     * label models, and its choices for their screens. Set after every package has merged
     * its own config, so nothing put back by a package's defaults undoes it.
     */
    protected function configurePackages(): void
    {
        $user = config('aurora.user_model');
        $isAuroras = fn (mixed $model): bool => is_string($model) && is_a($model, User::class, true);

        config(array_filter([
            'auth.providers.users.model' => $isAuroras(config('auth.providers.users.model')) ? null : $user,
            'passkeys.models.authenticatable' => $isAuroras(config('passkeys.models.authenticatable')) ? null : $user,
        ]));

        // Search works out of the box: the database (full-text on MySQL), unless a search
        // service is set up. Scout's own default, "collection", reads every page into PHP.
        $driver = config('scout.driver');

        if (blank($driver) || $driver === 'collection' || ($driver === 'algolia' && blank(config('scout.algolia.id')))) {
            config(['scout.driver' => 'database']);
        }

        config([
            'passkeys.models.passkey' => Passkey::class,
            'passkeys.redirect_to_after_login' => '/'.trim(config('aurora.path', 'admin'), '/'),
            'permission.models.role' => Role::class,
            'permission.models.permission' => Permission::class,
            'media-library.media_model' => Media::class,
            'media-library.queue_conversions_by_default' => false,
            'translation-loader.model' => LanguageLine::class,
            'translation-loader.translation_manager' => TranslationLoader::class,
            'filament-icon-picker.allowed_sets' => ['website-heroicons'],
            'filament-icon-picker.cache_icons' => false,
            // Only Aurora's roles screen: permissions are defined in code (Aurora\Support\Permissions),
            // so the plugin's raw permissions screen is left out. The whole list, not merged into the plugin's.
            'filament-spatie-roles-permissions.resources' => ['RoleResource' => RoleResource::class],
            'filament-spatie-roles-permissions.guard_names' => ['web' => 'web'],
            'filament-spatie-roles-permissions.navigation_section_group' => 'Access',
            'filament-spatie-roles-permissions.scope_to_tenant' => false,
            'filament-spatie-roles-permissions.scope_roles_to_tenant' => false,
            'filament-spatie-roles-permissions.default_guard_name' => 'web',
            'filament-spatie-roles-permissions.should_show_guard' => false,
            'filament-spatie-roles-permissions.icons.role_navigation' => 'phosphor-shield-check',
            'filament-spatie-roles-permissions.icons.permission_navigation' => 'phosphor-key',
            'filament-spatie-roles-permissions.sort.role_navigation' => 2,
            'filament-spatie-roles-permissions.sort.permission_navigation' => 3,
            'filament-spatie-roles-permissions.generator.excluded_policy_models' => [$user],
            'filament-spatie-roles-permissions.generator.user_model' => $user,
            'filament-spatie-roles-permissions.generator.policies_namespace' => 'Aurora\\Policies',
            // MCP clients: Claude and the editors that connect to it.
            'mcp.redirect_domains' => ['https://claude.ai', 'https://claude.com', 'http://localhost', 'http://127.0.0.1'],
            'mcp.custom_schemes' => ['claude', 'cursor', 'vscode'],
        ]);
    }

    /**
     * The website side: which site and language a request is for, and its pages. The site
     * comes first, before route parameters are looked up (a page, form or redirect is found on
     * the site asked for). Pages are routed after the app's own routes, so the app's win.
     */
    /**
     * Any change to what pages show clears the page cache (Aurora\Support\PageCache):
     * pages, their menus, layouts, settings, sites, languages, forms, redirects and media.
     */
    protected function clearPageCacheOnChanges(): void
    {
        $models = [Page::class, Menu::class, MenuItem::class, MenuLocation::class, NavigationLocation::class, Layout::class, Setting::class, Site::class, Locale::class, Form::class, Redirect::class, Asset::class, ImageFormat::class];

        Event::listen(array_merge(...array_map(fn (string $model): array => ["eloquent.saved: {$model}", "eloquent.deleted: {$model}"], $models)), fn () => PageCache::flush());
    }

    protected function registerWebsite(): void
    {
        // On the HTTP kernel as it is made (or now, if it already is): making it resets the
        // router's middleware groups and the sign-in redirect to the app's.
        $this->callAfterResolving(HttpKernelContract::class, function (HttpKernelContract $kernel): void {
            if (! $kernel instanceof HttpKernel) {
                return;
            }

            $kernel->prependMiddlewareToGroup('web', ResolveSite::class);
            $kernel->appendMiddlewareToGroup('web', UseSiteLocale::class);

            // Signing in (to approve an AI app, say): the app's own sign-in if it has one, else the admin's.
            Authenticate::redirectUsing(fn (): string => Route::has('login') ? route('login') : route('filament.admin.auth.login'));
        });

        if (config('aurora.routes', true)) {
            $this->app->booted(fn () => $this->app->routesAreCached() ? null : $this->loadRoutesFrom(__DIR__.'/../../routes/pages.php'));
        }
    }
}
