<?php

namespace Aurora\Livewire;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Models\Locale;
use Aurora\Models\Page;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\PeoplePicker;
use Aurora\Support\TableHeading;
use Closure;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Settings › Sites: the websites this CMS runs, each with its address, its
 * languages and the people who work on it. People who manage sites see every site.
 */
class SitesTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function mount(): void
    {
        abort_unless(SiteSettings::canManageSites(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Site::query()->withCount(['users']))
            ->heading(TableHeading::make('phosphor-globe-hemisphere-west', __('Sites')))
            ->description(__('Each site has its own pages, menus, redirects, forms and settings; the media library is shared. The default site answers on every address that isn’t another site’s domain.'))
            ->defaultSort('is_default', 'desc')
            ->reorderable('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('Site'))
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Site $record): string => $record->domain ?: ($record->is_default ? __('Any other address') : __('No domain: only in the admin'))),
                IconColumn::make('is_default')->label(__('Default site'))->boolean()->trueIcon('phosphor-check')->falseIcon(null),
                TextColumn::make('default_locale')
                    ->label(__('Language'))
                    ->state(fn (Site $record): string => Locale::options()[$record->default_locale ?? Locale::defaultCode()] ?? '')
                    ->description(fn (Site $record): ?string => filled($record->locales) ? trans_choice(':count language|:count languages', count($record->locales)) : __('Every language')),
                TextColumn::make('pages')
                    ->label(__('Pages'))
                    ->state(fn (Site $record): int => Page::acrossSites()->where('site_id', $record->getKey())->count()),
                TextColumn::make('users_count')
                    ->label(__('People'))
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? trans_choice(':count person|:count people', $state) : __('Default site only')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add a site'))
                    ->modalHeading(__('Add a site'))
                    ->icon('phosphor-plus')
                    ->model(Site::class)
                    ->schema(fn (): array => $this->formFields())
                    ->createAnother(false)
                    ->after(fn () => $this->refreshSidebar()),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading(fn (Site $record): string => __('Edit :site', ['site' => $record->name]))
                    ->schema(fn (): array => $this->formFields())
                    ->after(fn () => $this->refreshSidebar()),
                DeleteAction::make()
                    // Not the default site, nor the one being worked on (the admin would lose its place).
                    ->hidden(fn (Site $record): bool => $record->is_default || $record->is(Filament::getTenant()))
                    ->modalDescription(__('Its pages, menus, redirects, forms and settings are deleted too. The media library is shared, so it stays.'))
                    ->after(fn () => $this->refreshSidebar()),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function formFields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(80)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (?string $state, Get $get, Set $set) => blank($get('handle')) ? $set('handle', Str::slug((string) $state)) : null),
            TextInput::make('handle')
                ->label(__('Admin address'))
                // On the site's own domain once it has one (the admin answers there too), else APP_URL.
                ->prefix(fn (Get $get): string => Str::after(Site::adminUrl($get('domain')), '://').'/')
                ->required()
                ->maxLength(40)
                ->alphaDash()
                ->unique(Site::class, 'handle', ignoreRecord: true)
                ->helperText(__('Where the site is worked on in the admin.')),
            TextInput::make('domain')
                ->label(__('Domain'))
                ->placeholder('www.example.com')
                ->live(onBlur: true)
                ->maxLength(255)
                ->unique(Site::class, 'domain', ignoreRecord: true)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtolower(trim((string) parse_url(str_contains($state, '://') ? $state : 'https://'.$state, PHP_URL_HOST))) : null)
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $host = parse_url(str_contains((string) $value, '://') ? (string) $value : 'https://'.$value, PHP_URL_HOST);

                    if (filled($value) && (! is_string($host) || ! preg_match('/^(?=.{1,253}$)([a-z0-9-]{1,63}\.)*[a-z0-9-]{1,63}$/i', $host))) {
                        $fail(__('Enter a domain such as www.example.com.'));
                    }
                })
                ->helperText(__('The site answers on this address. Without one, only the default site is on the web; others are worked on in the admin.')),
            Select::make('default_locale')
                ->label(__('Default language'))
                ->options(fn (): array => Locale::options())
                ->placeholder(fn (): string => __('The CMS’s (:language)', ['language' => Locale::options()[Locale::defaultCode()] ?? Locale::defaultCode()]))
                ->live(),
            CheckboxList::make('locales')
                ->label(__('Languages'))
                ->options(fn (): array => Locale::options())
                ->columns(2)
                ->helperText(__('The languages this site’s pages can be in. None ticked: every language.')),
            Toggle::make('is_default')
                ->label(__('Default site'))
                ->helperText(__('Answers on every address that isn’t another site’s domain.'))
                ->disabled(fn (?Site $record): bool => (bool) $record?->is_default),
            Select::make('users')
                ->label(__('People'))
                ->relationship('users', 'name')
                ->multiple()
                ->preload()
                ->searchable()
                // Avatars and names, as everywhere people are picked.
                ->getOptionLabelFromRecordUsing(fn (User $record): string => PeoplePicker::option($record))
                ->allowHtml()
                ->helperText(__('Who works on this site. People who manage sites (and Super Admins) work on every site; people given no site work on the default one.')),
        ];
    }

    /**
     * The site switcher (in the sidebar) comes and goes with the number of sites.
     */
    protected function refreshSidebar(): void
    {
        $this->dispatch('refresh-sidebar');
    }

    public function render(): View
    {
        return view('aurora::livewire.sites-table');
    }
}
