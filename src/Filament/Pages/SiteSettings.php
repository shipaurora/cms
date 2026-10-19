<?php

namespace Aurora\Filament\Pages;

use Aurora\Enums\Api;
use Aurora\Filament\Auth\AuthenticatorApps;
use Aurora\Filament\Concerns\ConfirmsLeaving;
use Aurora\Filament\Concerns\ManagesBlockPreviews;
use Aurora\Filament\Concerns\ManagesImageFormats;
use Aurora\Filament\Concerns\ManagesLayouts;
use Aurora\Filament\Concerns\ManagesMenuLocations;
use Aurora\Filament\Concerns\ManagesWebhooks;
use Aurora\Filament\Concerns\TranslatesPageLabels;
use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Filament\Pages\Auth\SetUpRequiredPasskey;
use Aurora\Filament\Resources\LanguageLines\LanguageLineResource;
use Aurora\Filament\Resources\Pages\Actions\ReindexAllAction;
use Aurora\Filament\Resources\Pages\Actions\SearchPagesAction;
use Aurora\Livewire\ApiKeysTable;
use Aurora\Livewire\LanguagesTable;
use Aurora\Livewire\SitesTable;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\Copilot;
use Aurora\Support\PageCache;
use Aurora\Support\Passkeys;
use Aurora\Support\SeoChecks;
use Aurora\Support\SettingsTabs;
use Aurora\Support\SystemInfo;
use Aurora\Support\Themes;
use Aurora\Support\WebsiteAccess;
use Aurora\Support\WordPressImport;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * Site-wide settings: identity, brand colours, header, announcement bar, footer, languages,
 * sites and the AI assistant. One section at a time, picked in the page's side menu; leaving
 * one with unsaved changes asks first.
 *
 * @property-read Schema $form
 */
class SiteSettings extends Page implements HasSchemas
{
    use ConfirmsLeaving;
    use HasUnsavedDataChangesAlert;
    use ManagesBlockPreviews;
    use ManagesImageFormats;
    use ManagesLayouts;
    use ManagesMenuLocations;
    use ManagesWebhooks;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-gear';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'Site settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * The section open (?tab=security); see sectionKey().
     */
    #[Url]
    public ?string $tab = null;

    /**
     * What's being tried in Settings › Search index: never saved, nor a change to save.
     */
    public string $searchQuery = '';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    /**
     * @var array<string, Tab>|null
     */
    protected ?array $sections = null;

    /**
     * Languages live in a tab here; translating them keeps this entry active in the sidebar.
     *
     * @return array<int, string>
     */
    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return [static::getRouteName(), LanguageLineResource::getRouteBaseName().'.*'];
    }

    public static function canManageLanguages(): bool
    {
        return static::canAccess() && (bool) auth()->user()?->can('manage-languages');
    }

    /**
     * For people who manage sites.
     */
    public static function canManageSites(): bool
    {
        return static::canAccess() && (bool) auth()->user()?->can('manage-sites');
    }

    /**
     * The Languages tab, for links back to it.
     */
    public static function languagesUrl(): string
    {
        return static::getUrl(['tab' => 'languages-sites', 'section' => 'languages']);
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('manage-settings');
    }

    public function getSubheading(): ?string
    {
        return __('Changes apply to the whole website and the visual editor instantly. No deploy needed.');
    }

    public function mount(): void
    {
        // The API key is never sent back to the browser, even encrypted.
        // The site being worked on: its own settings, with the CMS's (the AI assistant) beside them.
        $this->form->fill(Arr::except(Setting::forCurrentSite(), [Copilot::KEY_SETTING]));

        // What the form holds now, to tell unsaved changes by (HasUnsavedDataChangesAlert).
        $this->rememberData();
    }

    /**
     * The pages of Settings, in the side menu's order: each a few tabs that belong together,
     * by key. A tab a package or site adds (SettingsTabs::register()) joins the page it names,
     * or gets a page of its own after Website. "saves": whether the page has a Save button
     * (the others save as they're changed, in dialogs of their own).
     *
     * @var array<string, array{label: string, icon: string, tabs: array<int, string>, saves: bool}>
     */
    protected const PAGES = [
        'website' => ['label' => 'Website', 'icon' => 'phosphor-browser', 'tabs' => ['branding'], 'saves' => true],
        'templates' => ['label' => 'Templates', 'icon' => 'phosphor-layout', 'tabs' => ['layouts', 'blocks', 'menu-locations'], 'saves' => false],
        'media' => ['label' => 'Media', 'icon' => 'phosphor-image', 'tabs' => ['image-formats'], 'saves' => false],
        'languages-sites' => ['label' => 'Languages & sites', 'icon' => 'phosphor-translate', 'tabs' => ['languages', 'sites'], 'saves' => false],
        'search-seo' => ['label' => 'Search & SEO', 'icon' => 'phosphor-magnifying-glass', 'tabs' => ['search', 'seo'], 'saves' => false],
        'performance' => ['label' => 'Performance', 'icon' => 'phosphor-lightning', 'tabs' => ['page-cache'], 'saves' => true],
        'integrations' => ['label' => 'Integrations', 'icon' => 'phosphor-plugs-connected', 'tabs' => ['rest-api', 'graphql', 'webhooks'], 'saves' => true],
        'security' => ['label' => 'Security', 'icon' => 'phosphor-shield-check', 'tabs' => ['sign-in', 'access', 'api-keys'], 'saves' => true],
        'appearance' => ['label' => 'Appearance', 'icon' => 'phosphor-paint-brush-broad', 'tabs' => ['admin-theme'], 'saves' => true],
        'ai' => ['label' => 'AI Assistant', 'icon' => 'phosphor-sparkle', 'tabs' => ['ai-assistant'], 'saves' => true],
        'system' => ['label' => 'System', 'icon' => 'phosphor-wrench', 'tabs' => ['maintenance', 'import', 'system-info'], 'saves' => false],
    ];

    /**
     * Each tab this person may open, for the command palette and global search: straight to
     * its page with it open.
     *
     * @return array<int, array{label: string, icon: ?string, url: string}>
     */
    public static function sectionLinks(): array
    {
        if (! static::canAccess()) {
            return [];
        }

        $settings = new static;

        return collect($settings->pages())
            ->flatMap(fn (array $page, string $pageKey): array => array_map(fn (string $tab): array => [
                'label' => (string) $settings->sections()[$tab]->getLabel(),
                'icon' => $settings->sections()[$tab]->getIcon(),
                'url' => static::getUrl(count($page['tabs']) > 1 ? ['tab' => $pageKey, 'section' => $tab] : ['tab' => $pageKey]),
            ], $page['tabs']))
            ->values()
            ->all();
    }

    /**
     * The page open: the one asked for, else the first this person may see.
     */
    public function sectionKey(): string
    {
        return array_key_exists((string) $this->tab, $this->pages()) ? (string) $this->tab : (string) array_key_first($this->pages());
    }

    /**
     * Each page in the side menu.
     *
     * @return array<int, NavigationItem>
     */
    public function getSubNavigation(): array
    {
        return collect($this->pages())
            ->keys()
            ->map(fn (string $key, int $index): NavigationItem => NavigationItem::make($this->pages()[$key]['label'])
                ->icon($this->pages()[$key]['icon'])
                ->url(static::getUrl(['tab' => $key]))
                ->isActiveWhen(fn (): bool => $this->sectionKey() === $key)
                ->sort($index))
            ->all();
    }

    public function form(Schema $schema): Schema
    {
        $tabs = $this->pages()[$this->sectionKey()]['tabs'];

        // One tab: its fields as the page. Several: a tab each, the open one in the address
        // (?section=blocks).
        $content = count($tabs) === 1
            ? Group::make($this->sections()[$tabs[0]]->getDefaultChildComponents())->columns($this->sections()[$tabs[0]]->getColumns())
            : Tabs::make($this->pages()[$this->sectionKey()]['label'])
                ->key('section')
                ->contained(false)
                ->persistTabInQueryString('section')
                ->tabs(array_map(fn (string $key): Tab => $this->sections()[$key]->key($key), $tabs));

        // Just the open page's fields: saving saves those.
        return $schema
            ->statePath('data')
            ->components([$content]);
    }

    /**
     * The pages this person may open, with their tabs, by key.
     *
     * @return array<string, array{label: string, icon: string, tabs: array<int, string>, saves: bool}>
     */
    protected function pages(): array
    {
        $sections = $this->sections();
        $pages = array_map(fn (array $page): array => [...$page, 'label' => __($page['label'])], static::PAGES);

        // A package's or site's own tabs: in the page they name, else a page of their own.
        $own = [];

        foreach (SettingsTabs::pages() as $key => $page) {
            if ($page !== null && isset($pages[$page])) {
                $pages[$page]['tabs'][] = $key;
            } elseif (isset($sections[$key])) {
                $own[$key] = ['label' => (string) $sections[$key]->getLabel(), 'icon' => (string) ($sections[$key]->getIcon() ?? 'phosphor-gear'), 'tabs' => [$key], 'saves' => true];
            }
        }

        $pages = [...array_slice($pages, 0, 1, true), ...$own, ...array_slice($pages, 1, null, true)];

        return array_filter(
            array_map(fn (array $page): array => [...$page, 'tabs' => array_values(array_filter($page['tabs'], fn (string $tab): bool => isset($sections[$tab])))], $pages),
            fn (array $page): bool => $page['tabs'] !== [],
        );
    }

    /**
     * The tabs this person may open, by key: the CMS's own and those packages and sites add.
     * Which page each is on: PAGES.
     *
     * @return array<string, Tab>
     */
    protected function sections(): array
    {
        // Each read on its own (its label, icon and fields), so each in a schema of this page.
        return $this->sections ??= array_map(fn (Tab $section): Tab => $section->container(Schema::make($this)), array_filter([
            'branding' => Tab::make(__('Branding'))->icon('phosphor-palette')->columns(3)->schema([
                Section::make(__('Identity'))
                    ->icon('phosphor-identification-card')
                    ->description(__('How the site introduces itself.'))
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('site_name')->label(__('Site name'))->required()->maxLength(60),
                        TextInput::make('contact_email')->label(__('Contact email'))->email()->prefixIcon('phosphor-envelope-simple'),
                        Textarea::make('tagline')->label(__('Tagline'))->rows(2)->maxLength(200)->columnSpanFull()
                            ->helperText(__('Shown in the footer.')),
                        AssetPicker::make('og_image')->label(__('Default share image'))
                            ->helperText(__('Used when a page has no share image of its own.'))->columnSpanFull(),
                    ]),
                Section::make(__('Brand colours'))
                    ->icon('phosphor-palette')
                    ->description(__('Every block paints with these. Try it!'))
                    ->columnSpan(1)
                    ->schema([
                        ColorPicker::make('brand_color')->label(__('Brand'))->hex()->regex('/^#[0-9a-fA-F]{6}$/')->live(),
                        ColorPicker::make('accent_color')->label(__('Accent'))->hex()->regex('/^#[0-9a-fA-F]{6}$/')->live(),
                        View::make('aurora::filament.pages.brand-preview')
                            ->viewData(fn (Get $get): array => ['brand' => $get('brand_color'), 'accent' => $get('accent_color')]),
                        Actions::make([
                            Action::make('resetColours')
                                ->label(__('Reset to defaults'))
                                ->link()
                                ->icon('phosphor-arrows-clockwise')
                                ->action(function (): void {
                                    $this->data['brand_color'] = '#7c3aed';
                                    $this->data['accent_color'] = '#22d3ee';
                                }),
                        ]),
                    ]),
            ]),
            // The website's own (its header, footer …): Aurora\Support\SettingsTabs.
            ...SettingsTabs::tabs(),
            // The layouts pages are drawn with, and the sizes made of every image: each saved as
            // it's changed, in a dialog of its own.
            'layouts' => Tab::make(__('Layouts'))->icon('phosphor-layout')->schema([
                Section::make(__('Layouts'))
                    ->icon('phosphor-layout')
                    ->description(__('How pages are drawn on the website. Each page picks one; new pages start with the default.'))
                    ->schema(fn (): array => $this->layoutRows()),
            ]),
            'image-formats' => Tab::make(__('Image formats'))->icon('phosphor-frame-corners')->schema([
                Section::make(__('Image formats'))
                    ->icon('phosphor-frame-corners')
                    ->description(__('The sizes the library makes of every image. Blocks pick the one that fits, and responsive formats let browsers choose the size that suits the screen.'))
                    ->schema(fn (): array => $this->formatRows()),
            ]),
            'menu-locations' => Tab::make(__('Menu locations'))->icon('phosphor-map-pin')->schema([
                Section::make(__('Menu locations'))
                    ->icon('phosphor-map-pin')
                    ->description(__('The places in the website’s templates a menu can be shown. Put a menu in one with “Locations”, under Menus.'))
                    ->schema(fn (): array => $this->menuLocationRows()),
            ]),
            // Every block, with the picture the visual editor's block list shows beside it.
            'blocks' => Tab::make(__('Blocks'))->icon('phosphor-squares-four')->schema([
                Section::make(__('Blocks'))
                    ->icon('phosphor-squares-four')
                    ->description(__('The blocks editors build pages from, with the picture shown beside each in the visual editor. Upload a screenshot to show your own.'))
                    ->schema(fn (): array => $this->blockRows()),
            ]),
            // Languages and their translations; a table of its own (Aurora\Livewire\LanguagesTable).
            'languages' => static::canManageLanguages() ? Tab::make(__('Languages'))
                ->icon('phosphor-translate')
                ->schema([
                    Livewire::make(LanguagesTable::class)->key('languages-table'),
                ]) : null,
            // The websites this CMS runs; a table of its own (Aurora\Livewire\SitesTable).
            'sites' => static::canManageSites() ? Tab::make(__('Sites'))
                ->icon('phosphor-globe-hemisphere-west')
                ->schema([
                    Livewire::make(SitesTable::class)->key('sites-table'),
                ]) : null,
            // What search finds, for those who manage search.
            'search' => auth()->user()?->can('manage-search Page') ? Tab::make(__('Search index'))->icon('phosphor-magnifying-glass')->schema([
                Section::make(__('Search index'))
                    ->icon('phosphor-magnifying-glass')
                    ->description(__('Try a search across the site’s pages: the ones it finds, most relevant first, and whether visitors can find them.'))
                    ->headerActions([ReindexAllAction::make()])
                    ->schema([
                        View::make('aurora::filament.pages.settings-search'),
                        View::make('aurora::filament.pages.search-results')
                            ->viewData(fn (): array => SearchPagesAction::results(trim($this->searchQuery))),
                    ]),
            ]) : null,
            // Live pages that could do better in search (Aurora\Support\SeoChecks).
            'seo' => Tab::make(__('SEO report'))->icon('phosphor-chart-line-up')->schema([
                Section::make(__('SEO report'))
                    ->icon('phosphor-chart-line-up')
                    ->description(__('Live pages that could do better in search results, and what would help. Open a page to fix it.'))
                    ->schema([
                        View::make('aurora::filament.pages.seo-report')->viewData(fn (): array => ['rows' => SeoChecks::report()]),
                    ]),
            ]),
            // Saved copies of pages for visitors (Aurora\Support\PageCache).
            'page-cache' => Tab::make(__('Page cache'))->icon('phosphor-lightning')->schema([
                Section::make(__('Page cache'))
                    ->icon('phosphor-lightning')
                    ->description(__('Makes the website faster. The first visit to a page saves a copy of it, and later visitors get that copy instead of waiting for the page to be built again. Saving anything in the CMS clears the copies, so visitors never see old content.'))
                    ->headerActions([
                        Action::make('clearPageCache')
                            ->label(__('Clear page cache'))
                            ->icon('phosphor-broom')
                            ->color('gray')
                            ->action(function (): void {
                                PageCache::flush();
                                Notification::make()->title(__('Page cache cleared'))->success()->send();
                            }),
                    ])
                    ->columns(2)
                    ->schema([
                        Toggle::make(PageCache::SETTING.'.enabled')
                            ->label(__('Cache pages to make them load faster'))
                            ->helperText(__('Only visitors who aren’t signed in get the saved copies. Pages with a form, password-protected pages and previews are always built fresh.'))
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make(PageCache::SETTING.'.minutes')
                            ->label(__('Refresh each copy after'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(1440)
                            ->default(60)
                            ->suffix(__('minutes'))
                            ->helperText(__('A safety net for changes made outside the CMS. Changes saved in the CMS clear the copies straight away, and scheduled pages still go live on time.'))
                            ->visible(fn (Get $get): bool => (bool) $get(PageCache::SETTING.'.enabled')),
                    ]),
            ]),
            // Pages, menus and settings for a separate frontend or app (Aurora\Support\PublicApi).
            'rest-api' => $this->apiTab(Api::Rest, __('Read the website’s live pages, menus and settings as JSON, each at its own address. Works with anything that can fetch a URL: a frontend built apart from Laravel, an app or another service. It only shows what visitors may see.')),
            'graphql' => $this->apiTab(Api::Graphql, __('Ask for the website’s live pages, menus and settings in one request, with exactly the fields you need. Handy for JavaScript frontends such as Next.js, Nuxt or Astro. It only shows what visitors may see.')),
            // The keys apps read both APIs with (Aurora\Models\ApiKey).
            'api-keys' => Tab::make(__('API keys'))->icon('phosphor-key')->schema([
                Livewire::make(ApiKeysTable::class)->key('api-keys-table'),
            ]),
            // Other systems told what happens to the content (Aurora\Support\Webhooks).
            'webhooks' => Tab::make(__('Webhooks'))->icon('phosphor-plugs-connected')->schema([
                Section::make(__('Webhooks'))
                    ->icon('phosphor-plugs-connected')
                    ->description(__('Tell other systems when pages change or a form is sent: a search service, a CDN to clear, Zapier or Make.'))
                    ->schema(fn (): array => $this->webhookRows()),
            ]),
            'sign-in' => Tab::make(__('Sign-in'))->icon('phosphor-shield-check')->columns(3)->schema([
                Section::make(__('Two-factor sign-in'))
                    ->icon('phosphor-shield-check')
                    ->description(__('Besides their password, people enter a code from an authenticator app on their phone, like Google Authenticator, 1Password or Authy. Everyone adds their apps on their own profile.'))
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make(AuthenticatorApps::REQUIRED_SETTING)
                            ->label(__('Require two-factor sign-in for everyone'))
                            ->helperText(function (): string {
                                $without = User::query()->whereDoesntHave('authenticatorApps')->count();

                                return trans_choice('{0} Everyone has an authenticator app.|{1} :count person has no authenticator app yet: they are asked to add one the next time they open the admin panel.|[2,*] :count people have no authenticator app yet: they are asked to add one the next time they open the admin panel.', $without);
                            }),
                    ]),
                Section::make(__('Passkeys'))
                    ->icon('phosphor-fingerprint')
                    // Browsers only make passkeys over HTTPS.
                    ->visible(fn (): bool => Passkeys::available())
                    ->description(__('People sign in with their fingerprint, face, screen lock or a security key instead of typing a password. Passkeys work over HTTPS only, in an up-to-date browser.'))
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make(SetUpRequiredPasskey::REQUIRED_SETTING)
                            ->label(__('Require a passkey for everyone'))
                            ->helperText(function (): string {
                                $without = User::query()->whereDoesntHave('passkeys')->count();

                                return trans_choice('{0} Everyone has a passkey.|{1} :count person has no passkey yet: they are asked to add one the next time they open the admin panel.|[2,*] :count people have no passkey yet: they are asked to add one the next time they open the admin panel.', $without);
                            }),
                    ]),
            ]),
            // The website open only to some addresses, for this site (Aurora\Support\WebsiteAccess).
            'access' => Tab::make(__('Website access'))->icon('phosphor-lock-key')->schema([
                Section::make(__('Website access'))
                    ->icon('phosphor-lock-key')
                    ->description(__('Open this website only to some addresses while it isn’t ready, such as a staging site or an office network. People signed in to the CMS always get in.'))
                    ->schema([
                        Toggle::make(WebsiteAccess::SETTING.'.restricted')
                            ->label(__('Only let in these addresses'))
                            ->helperText(__('Everyone else sees a page saying the website isn’t open yet.'))
                            ->live(),
                        Textarea::make(WebsiteAccess::SETTING.'.addresses')
                            ->label(__('Addresses'))
                            ->placeholder("203.0.113.7\n192.168.0.0/24")
                            ->rows(4)
                            ->extraInputAttributes(['class' => 'font-mono text-sm'])
                            ->helperText(fn (): string => __('One address or range per line. Yours is :ip.', ['ip' => request()->ip()]))
                            ->hintAction(
                                Action::make('addMyAddress')
                                    ->label(__('Add my address'))
                                    ->icon('phosphor-plus')
                                    ->action(fn (Get $get, Set $set) => $set(WebsiteAccess::SETTING.'.addresses', trim(trim((string) $get(WebsiteAccess::SETTING.'.addresses'))."\n".request()->ip()))),
                            )
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                foreach (array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $value))) as $address) {
                                    if (! WebsiteAccess::isValidAddress($address)) {
                                        $fail(__('“:address” isn’t an IP address or range.', ['address' => $address]));
                                    }
                                }
                            })
                            ->visible(fn (Get $get): bool => (bool) $get(WebsiteAccess::SETTING.'.restricted')),
                    ]),
            ]),
            // The admin's look, for everyone (Aurora\Support\Themes).
            'admin-theme' => Tab::make(__('Admin theme'))->icon('phosphor-paint-brush-broad')->schema([
                Section::make(__('Admin theme'))
                    ->icon('phosphor-paint-brush-broad')
                    ->description(__('How the admin looks, for everyone who works in it. Theme packages can add their own.'))
                    ->schema([
                        ViewField::make(Themes::SETTING)
                            ->hiddenLabel()
                            ->view('aurora::filament.pages.theme-picker')
                            ->viewData(['themes' => Themes::all()])
                            ->default(fn (): ?string => Themes::active())
                            ->formatStateUsing(fn (?string $state): ?string => $state ?? Themes::active())
                            ->in(fn (): array => array_keys(Themes::all())),
                    ]),
            ]),
            'ai-assistant' => Tab::make(__('AI Assistant'))->icon('phosphor-sparkle')->columns(3)->schema([
                Section::make(__('AI assistant'))
                    ->icon('phosphor-sparkle')
                    ->description(__('An assistant in the admin panel that reads and edits content for whoever is signed in, with exactly their permissions. It stays off until a provider, model and API key are set.'))
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Callout::make(fn (): string => Copilot::isConfigured() ? __('The assistant is on') : __('The assistant is off'))
                            ->description(fn (): string => match (true) {
                                Copilot::isConfigured() => __('People with the "Use the AI assistant" permission see a chat bubble in the bottom corner of the admin panel (or press Ctrl+Shift+K). Connecting external AI apps is a separate permission.'),
                                Copilot::isSetUp() => __('It is switched off. The provider, model and key are kept: switch it on again below.'),
                                default => __('Choose a provider and model and save an API key to switch it on.'),
                            })
                            ->color(fn (): string => Copilot::isConfigured() ? 'success' : 'gray')
                            ->icon(fn (): string => Copilot::isConfigured() ? 'phosphor-check-circle' : 'phosphor-pause')
                            ->columnSpanFull(),
                        // Off without losing the setup; there once it is set up.
                        Toggle::make('copilot.enabled')
                            ->label(__('Assistant switched on'))
                            ->helperText(__('Switch it off for everyone without removing the provider, model or key.'))
                            ->default(true)
                            // Never saved means on (Filament's toggle would read it as off).
                            ->afterStateHydrated(fn (Toggle $component) => $component->state(Copilot::isSwitchedOn()))
                            ->visible(fn (): bool => Copilot::isSetUp())
                            ->columnSpanFull(),
                        Select::make('copilot.provider')
                            ->label(__('Provider'))
                            ->options(collect(Copilot::PROVIDERS)->map(fn (array $provider): string => $provider['label'])->all())
                            ->placeholder(__('None (off)'))
                            ->live(),
                        Select::make('copilot.custom_api')
                            ->label(__('Speaks the API of'))
                            ->options(Copilot::CUSTOM_APIS)
                            ->helperText(__('Many providers and self-hosted models offer an OpenAI-compatible API.'))
                            ->visible(fn (Get $get): bool => $get('copilot.provider') === 'custom')
                            ->required(fn (Get $get): bool => $get('copilot.provider') === 'custom')
                            ->live(),
                        TextInput::make('copilot.custom_url')
                            ->label(__('Endpoint'))
                            ->url()
                            ->placeholder(fn (Get $get): string => $get('copilot.custom_api') === 'ollama' ? 'http://localhost:11434' : 'https://openrouter.ai/api/v1')
                            ->helperText(fn (Get $get): string => $get('copilot.custom_api') === 'ollama'
                                ? __('The server’s address only, such as http://localhost:11434. Leave off /api: the assistant adds it.')
                                : __('The base URL, usually ending in /v1.'))
                            ->visible(fn (Get $get): bool => $get('copilot.provider') === 'custom')
                            ->required(fn (Get $get): bool => $get('copilot.provider') === 'custom')
                            ->columnSpan(2),
                        TextInput::make('copilot.model')
                            ->label(__('Model'))
                            ->placeholder(fn (Get $get): string => 'e.g. '.(Copilot::PROVIDERS[$get('copilot.provider')]['example'] ?? __('the model id')))
                            ->helperText(__('The provider’s model id.'))
                            ->requiredWith('copilot.provider')
                            ->maxLength(100),
                        TextInput::make('copilot_api_key_input')
                            ->label(__('API key'))
                            ->password()
                            ->autocomplete('off')
                            ->dehydrated(false)
                            ->placeholder(fn (): string => Copilot::maskedKey() ?? __('Paste the key from your provider'))
                            ->helperText(fn (Get $get): string => match (true) {
                                Copilot::hasApiKey() => __('A key is saved and stored encrypted. Leave this empty to keep it.'),
                                $get('copilot.provider') === 'custom' && $get('copilot.custom_api') === 'ollama' => __('Usually not needed for Ollama.'),
                                default => __('Stored encrypted, and never shown again after saving.'),
                            })
                            ->suffixAction(
                                Action::make('removeCopilotKey')
                                    ->icon('phosphor-trash')
                                    ->color('danger')
                                    ->tooltip(__('Remove the saved key'))
                                    ->visible(fn (): bool => Copilot::hasApiKey())
                                    ->requiresConfirmation()
                                    ->modalHeading(__('Remove the API key?'))
                                    ->modalDescription(__('The assistant switches off until a new key is saved.'))
                                    ->action(function (): void {
                                        Copilot::storeApiKey(null);
                                        Notification::make()->title(__('API key removed'))->body(__('The assistant is off.'))->success()->send();
                                    }),
                            ),
                        Textarea::make('copilot.instructions')
                            ->label(__('Instructions'))
                            ->helperText(__('Who the assistant is, how it writes and how careful it is. {site} and {user} are filled in with the site name and the person chatting. How to use the CMS, the content guide and what is on screen are always added.'))
                            ->rows(16)
                            ->maxLength(10000)
                            ->columnSpanFull()
                            ->extraInputAttributes(['class' => 'font-mono text-sm'])
                            // Empty means "the default": show it, so there is something to edit.
                            ->afterStateHydrated(fn (Textarea $component, ?string $state) => blank($state) ? $component->state(Copilot::DEFAULT_INSTRUCTIONS) : null)
                            ->hintAction(
                                Action::make('resetCopilotInstructions')
                                    ->label(__('Reset to default'))
                                    ->icon('phosphor-arrow-counter-clockwise')
                                    ->requiresConfirmation()
                                    ->modalDescription(__('Your changes to the instructions are replaced with the default text. Save settings to keep it.'))
                                    ->action(fn (Set $set) => $set('copilot.instructions', Copilot::DEFAULT_INSTRUCTIONS)),
                            ),
                    ]),
            ]),

            // Keeping the CMS in good shape (Settings › System).
            'maintenance' => Tab::make(__('Maintenance'))->icon('phosphor-broom')->schema([
                Section::make(__('Maintenance'))
                    ->icon('phosphor-broom')
                    ->description(__('Things that fix most “why isn’t it showing” moments. None of them change content.'))
                    ->schema([
                        Actions::make([
                            Action::make('clearCaches')
                                ->label(__('Clear caches'))
                                ->icon('phosphor-broom')
                                ->color('gray')
                                ->requiresConfirmation()
                                ->modalDescription(__('The page cache, the app’s cache and its compiled views are made again as they’re needed. The first visits are a little slower.'))
                                ->action(function (): void {
                                    PageCache::flush();
                                    Artisan::call('cache:clear');
                                    Artisan::call('view:clear');
                                    Notification::make()->title(__('Caches cleared'))->success()->send();
                                }),
                            Action::make('sendTestEmail')
                                ->label(__('Send a test email'))
                                ->icon('phosphor-envelope-simple')
                                ->color('gray')
                                ->action(function (): void {
                                    try {
                                        Mail::raw(__('This is a test email from :site. If you read this, email works.', ['site' => Setting::get('site_name', config('app.name'))]), fn ($message) => $message->to(auth()->user()->email)->subject(__('Test email')));
                                        Notification::make()->title(__('Sent to :email', ['email' => auth()->user()->email]))->body(__('If it doesn’t arrive, check the mail settings in .env.'))->success()->send();
                                    } catch (Throwable $exception) {
                                        Notification::make()->title(__('The email couldn’t be sent'))->body($exception->getMessage())->danger()->send();
                                    }
                                }),
                            ReindexAllAction::make()->name('reindexAllFromSystem'),
                        ])->key('maintenance-actions'),
                    ]),
            ]),
            // Moving a WordPress site in (Aurora\Support\WordPressImport).
            'import' => Tab::make(__('Import'))->icon('phosphor-download-simple')->schema([
                Section::make(__('Import from WordPress'))
                    ->icon('phosphor-download-simple')
                    ->description(__('Bring a WordPress site in: its pages, its blog posts (under a Blog page), its images and its menus. Export it in WordPress under Tools › Export, “All content”.'))
                    ->schema([
                        Actions::make([
                            Action::make('importWordPress')
                                ->label(__('Import a WordPress export'))
                                ->icon('phosphor-upload-simple')
                                ->modalHeading(__('Import from WordPress'))
                                ->modalDescription(__('Pages come in as they are in WordPress: published or draft. Large sites import faster with php artisan aurora:import-wordpress.'))
                                ->modalSubmitActionLabel(__('Import'))
                                ->schema([
                                    FileUpload::make('file')
                                        ->label(__('Export file'))
                                        ->acceptedFileTypes(['text/xml', 'application/xml', 'application/rss+xml'])
                                        ->disk('local')
                                        ->directory('wordpress-imports')
                                        ->maxSize(51200)
                                        ->required(),
                                    Toggle::make('posts')->label(__('Blog posts too'))->default(true),
                                    Toggle::make('media')->label(__('Download the images into the media library'))->default(true),
                                ])
                                ->action(function (array $data): void {
                                    $path = Storage::disk('local')->path($data['file']);
                                    @set_time_limit(600);

                                    try {
                                        $result = (new WordPressImport(withPosts: (bool) $data['posts'], withMedia: (bool) $data['media']))->import($path);
                                        Notification::make()
                                            ->title(__('Imported from WordPress'))
                                            ->body(__(':pages pages, :posts posts, :images images and :menus menus.', ['pages' => $result['pages'], 'posts' => $result['posts'], 'images' => $result['images'], 'menus' => $result['menus']]).($result['skipped'] !== [] ? ' '.trans_choice(':count file couldn’t be downloaded.|:count files couldn’t be downloaded.', count($result['skipped'])) : ''))
                                            ->success()
                                            ->persistent()
                                            ->send();
                                    } catch (InvalidArgumentException $exception) {
                                        Notification::make()->title(__('That file can’t be imported'))->body($exception->getMessage())->danger()->send();
                                    } finally {
                                        Storage::disk('local')->delete($data['file']);
                                    }
                                }),
                        ])->key('import-actions'),
                    ]),
            ]),
            // What the CMS runs on (Aurora\Support\SystemInfo).
            'system-info' => Tab::make(__('System info'))->icon('phosphor-info')->schema([
                Section::make(__('System info'))
                    ->icon('phosphor-info')
                    ->description(__('What the CMS runs on, and what needs looking at.'))
                    ->schema([
                        View::make('aurora::filament.pages.system-info')->viewData(fn (): array => ['rows' => SystemInfo::rows()]),
                    ]),
            ]),
        ]));
    }

    /**
     * One of the website's APIs (Aurora\Support\PublicApi): switched on, whether it needs a
     * key, and how to call it.
     */
    protected function apiTab(Api $api, string $description): Tab
    {
        $setting = $api->setting();

        return Tab::make($api->getLabel())->icon($api->getIcon())->schema([
            Section::make($api->getLabel())
                ->icon($api->getIcon())
                ->description($description)
                ->headerActions([
                    // The same keys as the API keys tab, without leaving this one.
                    Action::make('manageApiKeys')
                        ->label(__('Manage keys'))
                        ->icon('phosphor-key')
                        ->color('gray')
                        ->modalHeading(__('API keys'))
                        ->modalDescription(__('The same keys work for the REST and GraphQL APIs. Make one for each app, and revoke it when the app stops using it.'))
                        ->modalIcon('phosphor-key')
                        ->modalWidth('4xl')
                        ->modalContent(view('aurora::filament.pages.manage-api-keys'))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Done')),
                ])
                ->schema([
                    Toggle::make($setting.'.enabled')
                        ->label(__('Switch on the :api', ['api' => $api->getLabel()]))
                        ->live(),
                    Toggle::make($setting.'.requires_key')
                        ->label(__('Only with an API key'))
                        ->default(true)
                        ->helperText(__('Apps send one of the API keys (Manage keys, above) as Authorization: Bearer <key>. The same keys work for both APIs.'))
                        ->visible(fn (Get $get): bool => (bool) $get($setting.'.enabled')),
                    View::make('aurora::filament.pages.api-endpoints')
                        ->viewData(['api' => $api])
                        ->visible(fn (Get $get): bool => (bool) $get($setting.'.enabled')),
                ]),
        ]);
    }

    /**
     * Build the open page again (its tabs, and the lists in them), so a change made in one of
     * its dialogs shows straight away.
     */
    protected function rebuildForm(): void
    {
        $this->sections = null;
        unset($this->cachedSchemas['form']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('Save settings'))
                ->action(fn () => $this->save())
                ->keyBindings(['mod+s'])
                // Pages whose tabs save as they're changed, through their own dialogs, have none.
                ->visible(fn (): bool => $this->pages()[$this->sectionKey()]['saves']),
        ];
    }

    public function content(Schema $schema): Schema
    {
        // Not wrapped in a <form>: the Languages and Sites sections hold tables whose dialogs
        // are forms of their own, and forms can't nest. Save is a header action instead (also
        // on ⌘S), hidden on those two, which save through their own dialogs.
        return $schema->components([
            EmbeddedSchema::make('form'),
        ]);
    }

    public function save(): void
    {
        $wasOn = Copilot::isConfigured();
        $themeBefore = Themes::active();

        Setting::put($this->form->getState());

        if (filled($key = $this->data['copilot_api_key_input'] ?? null)) {
            $hadKey = Copilot::hasApiKey();
            Copilot::storeApiKey($key);
            $this->data['copilot_api_key_input'] = null;

            // A first key is a request to use the assistant: switch it on.
            if (! $hadKey) {
                Setting::put(['copilot' => [...(Setting::get('copilot') ?? []), 'enabled' => true]]);
                $this->data['copilot']['enabled'] = true;
            }
        }

        // Nothing left unsaved: moving to another section doesn't ask.
        $this->rememberData();

        Notification::make()->title(__('Settings saved'))->body(__('The website is already using them.'))->success()->send();

        // Another theme: its stylesheet is in the page's head, so the page is loaded again.
        if (array_key_exists(Themes::SETTING, $this->data ?? []) && $themeBefore !== Themes::active()) {
            $this->redirect(static::getUrl(['tab' => 'appearance']));

            return;
        }

        // The assistant's chat bubble is part of the page layout: reload so it appears (or goes) now.
        if (Copilot::isConfigured() !== $wasOn) {
            $this->redirect(request()->headers->get('referer') ?: static::getUrl(), navigate: true);
        }
    }
}
