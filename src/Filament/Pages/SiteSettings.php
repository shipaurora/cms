<?php

namespace Aurora\Filament\Pages;

use Aurora\Filament\Auth\AuthenticatorApps;
use Aurora\Filament\Concerns\ConfirmsLeaving;
use Aurora\Filament\Concerns\ManagesImageFormats;
use Aurora\Filament\Concerns\ManagesLayouts;
use Aurora\Filament\Concerns\TranslatesPageLabels;
use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Filament\Pages\Auth\SetUpRequiredPasskey;
use Aurora\Filament\Resources\LanguageLines\LanguageLineResource;
use Aurora\Filament\Resources\Pages\Actions\ReindexAllAction;
use Aurora\Filament\Resources\Pages\Actions\SearchPagesAction;
use Aurora\Livewire\LanguagesTable;
use Aurora\Livewire\SitesTable;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\Copilot;
use Aurora\Support\Passkeys;
use Aurora\Support\SettingsTabs;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Livewire\Attributes\Url;
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
    use ManagesImageFormats;
    use ManagesLayouts;
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
        return static::getUrl(['tab' => 'languages']);
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
     * The sections this person may open, for the command palette and global search.
     *
     * @return array<int, array{label: string, icon: ?string, url: string}>
     */
    public static function sectionLinks(): array
    {
        if (! static::canAccess()) {
            return [];
        }

        return collect((new static)->getSubNavigation())
            ->map(fn (NavigationItem $item): array => ['label' => (string) $item->getLabel(), 'icon' => $item->getIcon(), 'url' => (string) $item->getUrl()])
            ->all();
    }

    /**
     * The section open: one of those the person may see, the first when none (or another) is asked for.
     */
    public function sectionKey(): string
    {
        return array_key_exists((string) $this->tab, $this->sections()) ? (string) $this->tab : (string) array_key_first($this->sections());
    }

    /**
     * Each section in the side menu, linking to the page with it open.
     *
     * @return array<int, NavigationItem>
     */
    public function getSubNavigation(): array
    {
        return collect($this->sections())
            ->values()
            ->map(fn (Tab $section, int $index): NavigationItem => NavigationItem::make((string) $section->getLabel())
                ->icon($section->getIcon())
                ->url(static::getUrl(['tab' => array_keys($this->sections())[$index]]))
                ->isActiveWhen(fn (): bool => $this->sectionKey() === array_keys($this->sections())[$index])
                ->sort($index))
            ->all();
    }

    public function form(Schema $schema): Schema
    {
        $section = $this->sections()[$this->sectionKey()];

        // Just the open section's fields: saving saves those.
        return $schema
            ->statePath('data')
            ->components([
                Group::make($section->getDefaultChildComponents())
                    ->columns($section->getColumns()),
            ]);
    }

    /**
     * The sections the person may open, by the key in the address (?tab=branding), in the
     * side menu's order: the CMS's own, with the website's (its header, footer …) after
     * Branding.
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
            'security' => Tab::make(__('Security'))->icon('phosphor-shield-check')->columns(3)->schema([
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
                            ->placeholder('https://openrouter.ai/api/v1')
                            ->helperText(__('The base URL, usually ending in /v1.'))
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
        ]));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('Save settings'))
                ->action(fn () => $this->save())
                ->keyBindings(['mod+s'])
                // Sections that save as they're changed, through their own dialogs.
                ->visible(fn (): bool => ! in_array($this->sectionKey(), ['layouts', 'image-formats', 'languages', 'sites', 'search'], true)),
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

        // The assistant's chat bubble is part of the page layout: reload so it appears (or goes) now.
        if (Copilot::isConfigured() !== $wasOn) {
            $this->redirect(request()->headers->get('referer') ?: static::getUrl(), navigate: true);
        }
    }
}
