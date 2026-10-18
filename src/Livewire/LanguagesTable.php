<?php

namespace Aurora\Livewire;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\LanguageLines\LanguageLineResource;
use Aurora\Models\Locale;
use Aurora\Models\User;
use Aurora\Support\ContentLocale;
use Aurora\Support\TableHeading;
use Aurora\Support\TranslationProgress;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Settings → Languages: the languages the CMS is available in. The starred one is the CMS
 * language (labels are written in it, and a site uses it until that site chooses its own,
 * under Sites). Anyone can pick any available one for the admin panel in their profile.
 * "Translate" opens that language's labels.
 */
class LanguagesTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function mount(): void
    {
        abort_unless(SiteSettings::canManageLanguages(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Locale::query())
            ->heading(TableHeading::make('phosphor-translate', __('Languages')))
            ->description(__('The starred language is the CMS language. Sites that have not chosen one use it, and labels are written in it. Choose a site’s own language under Sites. Everyone can pick any available language for the admin panel in their profile.'))
            ->defaultSort('is_default', 'desc')
            ->reorderable('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('Language'))
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Locale $record): string => $record->code.($record->code === ContentLocale::default() ? '' : ' · /'.Locale::prefixFor($record->code))),
                IconColumn::make('is_default')->label(__('Default'))->boolean()->trueIcon('phosphor-check')->falseIcon(null),
                // Switched on and off right here; the default language is always on.
                ToggleColumn::make('is_active')
                    ->label(__('Available'))
                    ->disabled(fn (Locale $record): bool => $record->is_default)
                    ->tooltip(fn (Locale $record): ?string => $record->is_default ? __('The CMS language is always available.') : null),
                TextColumn::make('progress')
                    ->label(__('Translated'))
                    ->state(fn (Locale $record): string => TranslationProgress::percentage($record->code).'%')
                    ->badge()
                    ->color(fn (Locale $record): string => match (true) {
                        TranslationProgress::percentage($record->code) >= 95 => 'success',
                        TranslationProgress::percentage($record->code) >= 50 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('people')
                    ->label(__('Used by'))
                    ->state(fn (Locale $record): string => trans_choice(':count person|:count people', $this->peopleUsing()[$record->code] ?? 0)),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add a language'))
                    ->modalHeading(__('Add a language'))
                    ->icon('phosphor-plus')
                    ->model(Locale::class)
                    ->schema(fn (): array => $this->formFields())
                    ->createAnother(false),
            ])
            ->recordActions([
                Action::make('translate')
                    ->label(__('Translate'))
                    ->icon('phosphor-translate')
                    ->url(fn (Locale $record): string => LanguageLineResource::getUrl('index', ['locale' => $record->code]))
                    ->visible(fn (): bool => LanguageLineResource::canViewAny()),
                EditAction::make()
                    ->modalHeading(fn (Locale $record): string => __('Edit :language', ['language' => $record->name]))
                    ->schema(fn (): array => $this->formFields()),
                DeleteAction::make()
                    ->hidden(fn (Locale $record): bool => $record->is_default)
                    ->modalDescription(__('Its translations are kept, in case the language is added again.')),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function formFields(): array
    {
        return [
            Select::make('code')
                ->label(__('Language'))
                ->options(fn (?Locale $record): array => collect(Locale::KNOWN)
                    ->except(Locale::query()->whereKeyNot($record?->getKey())->pluck('code')->all())
                    ->map(fn (string $name, string $code): string => "{$name} ({$code})")
                    ->all())
                ->searchable()
                ->required()
                ->unique(Locale::class, 'code', ignoreRecord: true)
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $set('name', Locale::KNOWN[$state] ?? null);
                    $set('prefix', $state !== null ? strtolower(str_replace('_', '-', $state)) : null);
                })
                ->disabledOn('edit'),
            TextInput::make('name')
                ->label(__('Shown as'))
                ->required()
                ->maxLength(60)
                ->helperText(__('In the language itself, as people pick it.')),
            // The language's code stays as it is; only the addresses change.
            TextInput::make('prefix')
                ->label(__('Address prefix'))
                ->prefix(Str::after(url('/'), '://').'/')
                ->required()
                ->maxLength(32)
                ->afterStateHydrated(fn (TextInput $component, ?string $state, ?Locale $record) => $component->state($state ?: $record?->code))
                ->dehydrateStateUsing(fn (?string $state): string => strtolower(trim((string) $state)))
                ->rule(fn (Get $get, ?Locale $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                    if (is_string($value) && ($problem = Locale::prefixProblem(strtolower(trim($value)), $record?->code ?? $get('code'))) !== null) {
                        $fail($problem);
                    }
                })
                ->helperText(__('The website’s addresses in this language start with it, as in /nb/about-us. A site’s default language has none. Changing it changes every address in the language.')),
            Toggle::make('is_active')
                ->label(__('Available'))
                ->helperText(__('People can choose it for the admin panel.'))
                ->default(true),
            Toggle::make('is_default')
                ->label(__('CMS language'))
                ->helperText(__('Sites that have not chosen a language use this one, and labels are written in it.')),
        ];
    }

    public function render(): View
    {
        return view('aurora::livewire.languages-table');
    }

    /**
     * How many people see the admin panel in each language. Anyone who hasn't picked one, or
     * picked one that is now switched off, gets the default language (see UsePreferredLocale).
     *
     * @return array<string, int>
     */
    #[Computed]
    public function peopleUsing(): array
    {
        return User::query()->pluck('locale')
            ->map(fn (?string $code): string => Locale::isActiveCode($code) ? $code : ContentLocale::default())
            ->countBy()
            ->all();
    }
}
