<?php

namespace Aurora\Providers;

use Althinect\FilamentSpatieRolesPermissions\FilamentSpatieRolesPermissionsPlugin;
use Aurora\Blocks\Blocks;
use Aurora\Filament\Auth\AuthenticatorApps;
use Aurora\Filament\GlobalSearchProvider;
use Aurora\Filament\MenuBuilderPlugin;
use Aurora\Filament\Pages\AiAssistant;
use Aurora\Filament\Pages\Auth\AcceptInvitation;
use Aurora\Filament\Pages\Auth\PasskeyTwoFactorChallenge;
use Aurora\Filament\Pages\Auth\SetUpRequiredPasskey;
use Aurora\Filament\Pages\Dashboard;
use Aurora\Filament\Resources\Menus\MenuResource;
use Aurora\Filament\Resources\Users\UserResource;
use Aurora\Http\Controllers\SidebarPreferenceController;
use Aurora\Http\Controllers\ThemePreferenceController;
use Aurora\Http\Middleware\RequirePasskey;
use Aurora\Http\Middleware\RequireTwoFactorSignIn;
use Aurora\Http\Middleware\SetSiteFromTenant;
use Aurora\Http\Middleware\UsePreferredLocale;
use Aurora\Livewire\PasskeyPrompt;
use Aurora\Models\Locale;
use Aurora\Models\Menu;
use Aurora\Models\MenuItem;
use Aurora\Models\MenuLocation;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\ContentLocale;
use Aurora\Support\Copilot;
use Aurora\Support\CopilotTools;
use Aurora\Support\CurrentSite;
use Aurora\Support\PaletteCommands;
use CarlJanzell\FilamentPageBuilder\FilamentPageBuilderPlugin;
use Datlechin\FilamentMenuBuilder\MenuPanel\ModelMenuPanel;
use Datlechin\FilamentMenuBuilder\MenuPanel\StaticMenuPanel;
use EslamRedaDiv\FilamentCopilot\FilamentCopilotPlugin;
use Filament\Actions\Action;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use LaraZeus\SpatieTranslatable\SpatieTranslatablePlugin;
use MarcelWeidum\Passkeys\PasskeysPlugin;
use Usamamuneerchaudhary\CommandPalette\FilamentCommandPalettePlugin;
use Usamamuneerchaudhary\CommandPalette\Support\Providers\ActionCommandProvider;
use Usamamuneerchaudhary\CommandPalette\Support\Providers\CustomCommandProvider;
use Usamamuneerchaudhary\CommandPalette\Support\Providers\NavigationCommandProvider;

class AdminPanelProvider extends PanelProvider
{
    /**
     * The admin's look: a theme a package registers with Filament, by its id ("aurora", from
     * aurora/aurora, unless another is named), or a Vite entry the site builds itself
     * (a path ending in .css).
     */
    public static function useTheme(Panel $panel, ?string $theme): Panel
    {
        $theme = filled($theme) ? $theme : 'aurora';

        return str_ends_with($theme, '.css') ? $panel->viteTheme($theme) : $panel->theme($theme);
    }

    public function panel(Panel $panel): Panel
    {
        // Sites: the admin works on one at a time, at /admin/{site}/…. There is always the
        // default site; more are added under Settings › Sites.
        $panel
            ->tenant(Site::class, slugAttribute: 'handle', ownershipRelationship: 'site')
            ->tenantMiddleware([SetSiteFromTenant::class], isPersistent: true)
            // The site switcher sits at the top of the sidebar's own card (below), and only with
            // more than one site to work on. Its dropdown is teleported, so the collapsed rail
            // doesn't clip it.
            ->tenantMenu(false)
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn (): string => (auth()->user()?->accessibleSites()->count() ?? 0) > 1
                ? Blade::render('<x-filament-panels::tenant-menu teleport />')
                : '');

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Two-factor sign-in with authenticator apps, any number per person (your profile).
            // "Required" here only sets up the set-up page and its middleware; whether anyone is
            // sent there is Settings → Security, checked per request (RequireTwoFactorSignIn).
            ->multiFactorAuthentication(
                [AuthenticatorApps::make()->recoverable()->brandName(config('aurora.name'))],
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(RequireTwoFactorSignIn::class)
            // Invitation links (signed, expiring): open to guests, like the login page.
            ->routes(function (): void {
                Route::get('invitations/{invitation}', AcceptInvitation::class)
                    ->middleware('signed')
                    ->name('invitations.accept');
                // After a passkey, the authenticator app's code (AuthenticateWithPasskeyController).
                Route::get('passkey-challenge', PasskeyTwoFactorChallenge::class)->name('auth.passkey-challenge');
            })
            ->brandName(fn (): string => config('aurora.name'))
            // "Profile" opens your own record in the Users resource: one place to edit any account.
            // The user menu (see resources/views/vendor/filament-panels/components/user-menu):
            // a card for you, then these, an appearance switch and "Sign out".
            ->userMenuItems([
                'profile' => fn (Action $action): Action => $action
                    ->label(fn (): string => __('Your profile'))
                    ->icon('phosphor-user-circle')
                    ->url(fn (): string => UserResource::getUrl('edit', ['record' => auth()->user()]))
                    // Pages outside a site (setting up two-factor sign-in) have no profile to link to.
                    ->visible(fn (): bool => Filament::getTenant() !== null),
                'ai-apps' => Action::make('aiApps')
                    ->label(fn (): string => __('Connected AI apps'))
                    ->icon('phosphor-sparkle')
                    ->url(fn (): string => AiAssistant::getUrl())
                    ->visible(fn (): bool => Filament::getTenant() !== null && AiAssistant::canAccess()),
                'website' => Action::make('website')
                    ->label(fn (): string => __('View website'))
                    ->icon('phosphor-arrow-square-out')
                    ->url(fn (): string => CurrentSite::url('/'), shouldOpenInNewTab: true),
                'logout' => fn (Action $action): Action => $action
                    ->label(fn (): string => __('Sign out'))
                    ->icon('phosphor-sign-out'),
            ])
            ->brandLogo(fn () => view('aurora::filament.brand'))
            ->brandLogoHeight('1.75rem')
            ->favicon('/aurora/brand/brand-mark.svg')
            ->colors([
                'primary' => Color::Violet,
                'gray' => Color::Zinc,
                'violet' => Color::Violet,
            ])
            ->font('Inter')
            // Aurora's theme (aurora/aurora), another theme package's, or the site's own build.
            ->tap(fn (Panel $panel) => static::useTheme($panel, config('aurora.theme')))
            // Dark by default; each person's own choice is saved on their account (see below).
            ->defaultThemeMode(ThemeMode::Dark)
            ->sidebarCollapsibleOnDesktop()
            ->collapsedSidebarWidth('5.5rem')
            ->maxContentWidth(Width::SevenExtraLarge)
            ->unsavedChangesAlerts()
            // Records, and the sections of Settings, straight to them.
            ->globalSearch(GlobalSearchProvider::class)
            ->databaseTransactions()
            // The bell in the topbar: pages sent for review, new form submissions and the like
            // (Aurora\Support\CmsNotifications), checked every half minute.
            // Not on the pages that make people add a passkey or an authenticator app first.
            ->databaseNotifications(fn (): bool => ! request()->routeIs('filament.admin.auth.*'))
            ->databaseNotificationsPolling('30s')
            ->navigationGroups([
                // Daily work first; set-up and administration last, with Settings at the very end.
                // Keyed by the name resources use; labelled in the person's language.
                'Content' => NavigationGroup::make()->label(fn (): string => __('Content'))->icon(null),
                'AI' => NavigationGroup::make()->label(fn (): string => __('AI'))->icon(null),
                'Administration' => NavigationGroup::make()->label(fn (): string => __('Administration'))->icon(null),
            ])
            ->discoverResources(in: __DIR__.'/../Filament/Resources', for: 'Aurora\Filament\Resources')
            ->discoverPages(in: __DIR__.'/../Filament/Pages', for: 'Aurora\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: __DIR__.'/../Filament/Widgets', for: 'Aurora\Filament\Widgets')
            ->widgets([])
            ->plugins([
                FilamentPageBuilderPlugin::make()
                    // The website's blocks (Aurora\Blocks\Blocks::register(), in its service provider).
                    ->blocks(array_values(Blocks::all()))
                    ->recordModel(Page::class)
                    ->blocksAttribute('blocks')
                    ->canvasStylesView('aurora::filament.page-builder.canvas-styles')
                    ->allowCode(fn (): bool => (bool) auth()->user()?->can('use-code-blocks'))
                    ->styleTokens([
                        'padding' => ['none' => 'None', 'sm' => 'S', 'md' => 'M', 'lg' => 'L', 'xl' => 'XL'],
                        'background' => ['none' => 'None', 'muted' => 'Soft grey', 'brand-soft' => 'Brand tint', 'card' => 'Card'],
                        'width' => ['narrow' => 'Narrow', 'default' => 'Default', 'wide' => 'Wide', 'full' => 'Full'],
                        'align' => ['start' => 'Start', 'center' => 'Centre', 'end' => 'End'],
                    ])
                    ->shortcode('site_name', fn (): string => e(Setting::get('site_name', config('app.name'))), fn (): string => __('The site name from Site settings.'), '[site_name]')
                    ->shortcode('page_count', fn (): string => (string) Page::query()->live()->count(), fn (): string => __('Number of live pages.'), '[page_count]'),
                // Translating pages and other content: a language switcher on their edit pages.
                SpatieTranslatablePlugin::make()
                    ->getLocaleLabelUsing(fn (string $locale): string => ContentLocale::all()[$locale] ?? $locale),
                // Menu item titles per language (Aurora\Models\MenuItem), as tabs in the item's edit
                // form, once the website has more than one language. The default one first.
                tap(MenuBuilderPlugin::make(), fn (MenuBuilderPlugin $plugin) => count($locales = array_keys(Locale::options())) > 1 ? $plugin->translatable($locales) : null)
                    ->usingResource(MenuResource::class)
                    ->usingMenuModel(Menu::class)
                    ->usingMenuItemModel(MenuItem::class)
                    ->usingMenuLocationModel(MenuLocation::class)
                    ->addMenuPanels([
                        ModelMenuPanel::make('Pages')
                            ->model(Page::class)
                            ->icon('phosphor-file-text')
                            ->description('Linked items follow the page when its address changes.')
                            ->paginate(8),
                        StaticMenuPanel::make('Shortcuts')
                            ->icon('phosphor-lightning')
                            ->collapsed()
                            ->add('Home', '/')
                            ->add('Sitemap', '/sitemap.xml'),
                    ])
                    ->showCustomLinkPanel(true)
                    ->showCustomTextPanel(true)
                    ->navigationGroup('Content')
                    ->navigationLabel('Menus')
                    ->navigationIcon('phosphor-text-align-left')
                    ->navigationSort(4),
                FilamentSpatieRolesPermissionsPlugin::make(),
                PasskeysPlugin::make(),
                // ⌘K / Ctrl+K: actions (Aurora\Support\PaletteCommands), every sidebar screen,
                // records from global search, theme and sign out. The plugin's generic
                // "List …" / "Create …" commands are left out: the sidebar entries and our
                // own actions say it better.
                FilamentCommandPalettePlugin::make()
                    ->providers([NavigationCommandProvider::class, ActionCommandProvider::class, CustomCommandProvider::class])
                    ->commands(fn (): array => PaletteCommands::all())
                    ->placeholder(fn (): string => __('Search, or type what you want to do…'))
                    ->template('groups')
                    ->maxResults(20)
                    // Enough that every kind of record (roles too) gets a turn after the pages and files.
                    ->recordSearch(true, 12)
                    ->publishViewsCommand(false),
                // The in-panel AI copilot. Hidden for everyone until it is set up in Site settings
                // (Copilot::isConfigured()), and only for people allowed to use the AI assistant.
                FilamentCopilotPlugin::make()
                    ->authorizeUsing(fn (User $user): bool => Copilot::isConfigured() && $user->is_active !== false && $user->can('use-assistant'))
                    ->globalTools([fn (): array => CopilotTools::forCurrentUser()])
                    ->managementEnabled(false),
            ])
            // The theme and whether the sidebar is collapsed are stored on the user: apply theirs
            // before Filament reads them, so they follow them to any browser without a flash;
            // resources/js/admin/theme.js and sidebar.js save changes. Guests (the sign-in page)
            // get the defaults.
            ->renderHook(PanelsRenderHook::HEAD_START, fn (): string => auth()->check()
                ? '<meta name="cms-theme-url" content="'.e(route('filament.admin.preferences.theme')).'">'
                    .'<meta name="cms-sidebar-url" content="'.e(route('filament.admin.preferences.sidebar')).'">'
                    .'<script>try {'
                    .' localStorage.setItem(\'filament:theme:mode\', '.json_encode(auth()->user()->theme ?? ThemeMode::Dark->value).');'
                    .' localStorage.setItem(\'filament:sidebar:desktop_open\', '.json_encode(json_encode(! (auth()->user()->sidebar_collapsed ?? true))).');'
                    .' localStorage.setItem(\'filament:sidebar:open\', '.json_encode(json_encode(! (auth()->user()->sidebar_collapsed ?? true))).');'
                    .' } catch (e) {}</script>'
                : '')
            ->authenticatedRoutes(function (): void {
                Route::post('preferences/theme', ThemePreferenceController::class)->name('preferences.theme');
                Route::post('preferences/sidebar', SidebarPreferenceController::class)->name('preferences.sidebar');
                Route::get('passkeys/set-up', SetUpRequiredPasskey::class)->name('auth.passkeys.set-up-required');
            })
            // Once, right after signing in: an offer to add a passkey (Aurora\Livewire\PasskeyPrompt).
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => PasskeyPrompt::shouldOffer() ? Blade::render('<livewire:passkey-prompt />') : '')
            // Extra fonts, the admin's scripts and what they need (resources/views/filament/head.blade.php).
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('aurora::filament.head')->render())
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): string => view('aurora::filament.demo-login')->render())
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // Each person's preferred language, on every request (Livewire updates too).
                UsePreferredLocale::class,
            ], isPersistent: true)
            // Settings → Security: no passkey, no admin (until one is added).
            ->authMiddleware([RequirePasskey::class])
            ->spa();
    }
}
