<?php

namespace Aurora\Filament\Resources\LanguageLines;

use Aurora\Filament\Concerns\TranslatesResourceLabels;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\LanguageLines\Pages\ManageLanguageLines;
use Aurora\Models\LanguageLine;
use Aurora\Models\Locale;
use Aurora\Support\TranslationLoader;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Every label in the website and the admin panel, the app's own, Laravel's and the
 * packages', in every language, edited in place. They are imported from the lang files
 * (Aurora\Support\TranslationSync, on seed and from here); edits made here win, and
 * `php artisan aurora:export-translations` writes them back to the lang files.
 */
class LanguageLineResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = LanguageLine::class;

    /**
     * Shared by every site: not tied to the site picked in the admin.
     */
    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-translate';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Translations';

    protected static ?string $modelLabel = 'label';

    protected static ?int $navigationSort = 4;

    /**
     * Laravel's own lang files, as groups.
     *
     * @var array<int, string>
     */
    public const LARAVEL_GROUPS = ['auth', 'pagination', 'passwords', 'validation'];

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('translate');
    }

    /**
     * Reached from Settings → Languages. Only in the sidebar for people who may translate
     * but can't open that tab.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny() && ! SiteSettings::canManageLanguages();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $locales = Locale::options();
        $default = Locale::defaultCode();

        return $table
            ->defaultSort('key')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('key')
                    ->label(fn (): string => __('Label').' · '.($locales[$default] ?? $default))
                    ->state(fn (LanguageLine $record): string => $record->text[$default] ?? $record->textIn($default) ?? $record->key)
                    // The key only when it says something the text doesn't.
                    ->description(fn (LanguageLine $record): ?string => $record->fullKey() === ($record->text[$default] ?? null) ? null : $record->fullKey())
                    ->wrap()
                    ->limit(100)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query) => $query
                        ->where('key', 'like', "%{$search}%")
                        ->orWhere('group', 'like', "%{$search}%")
                        ->orWhere('text', 'like', "%{$search}%"))),
                // One language at a time (the tabs above the table): it scales to any number.
                TextInputColumn::make('translation')
                    ->label(fn ($livewire): string => $locales[static::translatingInto($livewire)] ?? '')
                    ->state(fn (LanguageLine $record, $livewire): ?string => $record->text[static::translatingInto($livewire)] ?? null)
                    ->placeholder(fn (LanguageLine $record, $livewire): string => $record->imported[static::translatingInto($livewire)] ?? $record->fileText(static::translatingInto($livewire)) ?? __('Not translated'))
                    ->withAi(
                        'A translation of this label, used in the website or the admin panel. Translate the meaning, not word by word; keep :placeholders, | plural forms and the same capitalisation style.',
                        language: fn ($livewire): string => static::translatingInto($livewire),
                    )
                    ->withoutAiDialog()
                    ->updateStateUsing(function (LanguageLine $record, ?string $state, $livewire): ?string {
                        $record->putTranslation(static::translatingInto($livewire), $state)->save();

                        return $state;
                    })
                    ->extraAttributes(['class' => 'min-w-72']),
                TextColumn::make('source')
                    ->state(fn (LanguageLine $record): string => $record->source())
                    ->badge()
                    ->color(fn (string $state): string => $state === 'App' ? 'primary' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('missing')
                    ->label(__('Needs translation'))
                    ->toggle()
                    ->query(fn (Builder $query, $livewire): Builder => $query->whereIn('id', static::untranslatedIds(static::translatingInto($livewire)))),
                SelectFilter::make('source')
                    ->label(__('From'))
                    ->options(fn (): array => ['app' => __('The website and admin (App)'), 'laravel' => __('Laravel (validation, sign-in)'), ...static::packageOptions()])
                    ->default('app')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'app' => $query->where('group', 'not like', '%::%')->whereNotIn('group', self::LARAVEL_GROUPS),
                        'laravel' => $query->whereIn('group', self::LARAVEL_GROUPS),
                        default => $query->where('group', 'like', $data['value'].'::%'),
                    }),
                SelectFilter::make('edited')
                    ->label(__('Edited'))
                    ->options(['yes' => __('Edited here'), 'no' => __('As in the lang files')])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        $edited = LanguageLine::query()->get(['id', 'text', 'imported'])->filter(fn (LanguageLine $line): bool => $line->editedLocales() !== [])->modelKeys();

                        return $data['value'] === 'yes' ? $query->whereIn('id', $edited) : $query->whereNotIn('id', $edited);
                    }),
            ])
            ->recordActions([
                Action::make('allLanguages')
                    ->label(__('All languages'))
                    ->icon('phosphor-translate')
                    ->color('gray')
                    ->modalHeading(fn (LanguageLine $record): string => Str::limit($record->text[$default] ?? $record->key, 80))
                    ->modalDescription(fn (LanguageLine $record): string => $record->fullKey())
                    ->modalSubmitActionLabel(__('Save'))
                    ->fillForm(fn (LanguageLine $record): array => collect($locales)->mapWithKeys(fn (string $name, string $code): array => [$code => $record->text[$code] ?? null])->all())
                    ->schema(fn (LanguageLine $record): array => collect($locales)->map(fn (string $name, string $code): Textarea => Textarea::make($code)
                        ->label($name)
                        ->rows(1)
                        ->autosize()
                        ->placeholder($record->imported[$code] ?? $record->fileText($code) ?? __('Not translated')))
                        ->values()
                        ->all())
                    ->action(function (LanguageLine $record, array $data): void {
                        foreach ($data as $code => $value) {
                            $record->putTranslation($code, $value);
                        }

                        $record->save();

                        Notification::make()->title(__('Saved'))->success()->send();
                    }),
                Action::make('reset')
                    ->label(__('Reset'))
                    ->icon('phosphor-arrow-u-up-left')
                    ->color('gray')
                    ->visible(fn (LanguageLine $record): bool => $record->editedLocales() !== [])
                    ->requiresConfirmation()
                    ->modalHeading(__('Reset this label?'))
                    ->modalDescription(__('Brings back the text from the lang files in every language.'))
                    ->action(function (LanguageLine $record): void {
                        $record->flushGroupCache();
                        $record->text = $record->imported ?? [];
                        $record->save();

                        Notification::make()->title(__('Label reset'))->success()->send();
                    }),
            ]);
    }

    /**
     * The language being translated into: the tab picked above the table, or the first
     * language besides the default (the default itself when it's the only one).
     */
    public static function translatingInto(mixed $livewire = null): string
    {
        $codes = array_keys(Locale::options());

        // Opened from a language under Languages, or a tab picked above the table.
        foreach ([$livewire?->locale ?? null, $livewire?->activeTab ?? null] as $choice) {
            if (in_array($choice, $codes, true)) {
                return $choice;
            }
        }

        return collect($codes)->first(fn (string $code): bool => $code !== Locale::defaultCode()) ?? Locale::defaultCode();
    }

    /**
     * Labels that read in neither the database nor the lang files in a locale.
     *
     * @return array<int, string>
     */
    public static function untranslatedIds(string $locale): array
    {
        // The tabs, the table and "Translate all" ask for the same language: worked out once.
        return once(fn (): array => static::findUntranslatedIds($locale));
    }

    /**
     * @return array<int, string>
     */
    protected static function findUntranslatedIds(string $locale): array
    {
        $loader = app('translator')->getLoader();
        $ids = [];

        foreach (LanguageLine::query()->get(['id', 'group', 'key', 'text'])->groupBy('group') as $group => $lines) {
            [$namespace, $name] = str_contains($group, '::') ? explode('::', $group, 2) : ['*', $group];
            $files = $loader instanceof TranslationLoader ? $loader->fileTranslations($locale, $name, $namespace) : [];

            foreach ($lines as $line) {
                $fromFiles = $group === '*' ? ($files[$line->key] ?? null) : Arr::get($files, $line->key);

                if (blank($line->text[$locale] ?? null) && ! is_string($fromFiles)) {
                    $ids[] = $line->id;
                }
            }
        }

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    protected static function packageOptions(): array
    {
        return LanguageLine::query()
            ->where('group', 'like', '%::%')
            ->pluck('group')
            ->map(fn (string $group): string => str($group)->before('::')->toString())
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $namespace): array => [$namespace => str($namespace)->replace(['-', '_'], ' ')->title()->toString()])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLanguageLines::route('/'),
        ];
    }
}
