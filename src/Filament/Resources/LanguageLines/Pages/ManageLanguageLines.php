<?php

namespace Aurora\Filament\Resources\LanguageLines\Pages;

use Aurora\Filament\Concerns\WritesColumnsWithAi;
use Aurora\Filament\Forms\Components\WriteWithAi;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\LanguageLines\LanguageLineResource;
use Aurora\Models\LanguageLine;
use Aurora\Models\Locale;
use Aurora\Support\AiTranslator;
use Aurora\Support\TranslationSync;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Throwable;

class ManageLanguageLines extends ManageRecords
{
    use WritesColumnsWithAi;

    protected static string $resource = LanguageLineResource::class;

    /**
     * Settings › Languages, for people who manage languages there.
     *
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        if (! SiteSettings::canManageLanguages()) {
            return [];
        }

        return [
            SiteSettings::getUrl() => SiteSettings::getNavigationLabel(),
            SiteSettings::languagesUrl() => __('Languages'),
        ];
    }

    public function getTitle(): string
    {
        return filled($this->locale)
            ? __('Translate into :language', ['language' => Locale::options()[LanguageLineResource::translatingInto($this)] ?? $this->locale])
            : __('Translations');
    }

    /**
     * The language picked under Languages ("Translate"); without one, tabs pick it.
     */
    #[Locked]
    #[Url]
    public ?string $locale = null;

    /**
     * One tab per language to translate into, with how many labels still need it.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        if (filled($this->locale)) {
            return [];
        }

        $locales = Locale::options();

        return collect($locales)
            ->map(fn (string $name, string $code): Tab => Tab::make($name)
                ->badge(fn (): ?string => ($count = count(LanguageLineResource::untranslatedIds($code))) > 0 ? (string) $count : null)
                ->badgeColor('warning')
                ->badgeTooltip(__('Labels without a translation')))
            ->all();
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return LanguageLineResource::translatingInto();
    }

    public function getSubheading(): ?string
    {
        return __('Every label in the website and the admin panel, imported from the lang files. Edit a text to change it here; reset brings back the lang file’s text.');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->translateAllAction(),
            Action::make('removeUnused')
                ->label(__('Remove unused'))
                ->icon('phosphor-trash')
                ->color('gray')
                ->visible(fn (): bool => $this->unusedQuery()->exists())
                ->requiresConfirmation()
                ->modalHeading(__('Remove labels the code no longer uses?'))
                ->modalDescription(fn (): string => trans_choice(':count label was not found in the last import. Its translations are removed too.|:count labels were not found in the last import. Their translations are removed too.', $this->unusedQuery()->count()))
                ->action(function (): void {
                    $count = $this->unusedQuery()->get()->each->delete()->count();

                    Notification::make()->title(trans_choice('Removed :count unused label|Removed :count unused labels', $count))->success()->send();
                }),
            Action::make('sync')
                ->label(__('Import from lang files'))
                ->icon('phosphor-arrows-clockwise')
                ->requiresConfirmation()
                ->modalHeading(__('Import labels from the lang files?'))
                ->modalDescription(__('Adds new labels and updates the ones nobody has edited here. Your edits are kept.'))
                ->action(function (TranslationSync $sync): void {
                    $result = $sync->import();

                    Notification::make()
                        ->title(__('Imported :count labels', ['count' => $result['found']]))
                        ->body(__(':added new, :updated updated.', ['added' => $result['added'], 'updated' => $result['updated']]))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Opened for one language: every label still without a translation, translated by the
     * AI assistant from the default language's text, ready to review and edit in the table.
     */
    protected function translateAllAction(): Action
    {
        $language = fn (): string => Locale::options()[$this->locale] ?? (string) $this->locale;

        return Action::make('translateAll')
            ->label(__('Translate all'))
            ->icon('phosphor-sparkle')
            ->color('gray')
            ->visible(fn (): bool => array_key_exists((string) $this->locale, Locale::options())
                && $this->locale !== Locale::defaultCode()
                && WriteWithAi::isAvailable()
                && LanguageLineResource::untranslatedIds($this->locale) !== [])
            ->requiresConfirmation()
            ->modalIcon('phosphor-sparkle')
            ->modalHeading(fn (): string => __('Translate into :language with AI?', ['language' => $language()]))
            ->modalDescription(fn (): string => trans_choice(':count label has no translation yet. The assistant translates it from the default language; you can edit it afterwards.|:count labels have no translation yet. The assistant translates them from the default language; you can edit them afterwards.', count(LanguageLineResource::untranslatedIds($this->locale))))
            ->modalSubmitActionLabel(__('Translate'))
            ->action(function (Action $action): void {
                $default = Locale::defaultCode();
                $lines = LanguageLine::query()->whereKey(LanguageLineResource::untranslatedIds($this->locale))->get()->keyBy('id');
                $texts = $lines
                    ->map(fn (LanguageLine $line): string => (string) ($line->textIn($default) ?? ($line->group === '*' ? $line->key : '')))
                    ->filter(fn (string $text): bool => filled($text))
                    ->all();

                // Many labels take a few requests to the provider.
                set_time_limit(300);

                try {
                    $translated = AiTranslator::translate($texts, $this->locale, $default);
                } catch (Throwable $exception) {
                    report($exception);
                    Notification::make()->title(__('The assistant couldn’t translate them'))->body(Str::limit($exception->getMessage(), 200))->danger()->send();
                    $action->halt();
                }

                foreach ($translated as $id => $text) {
                    $lines[$id]->putTranslation($this->locale, $text)->save();
                }

                // What's left untranslated changed: worked out again for this render.
                Once::flush();

                Notification::make()
                    ->title(trans_choice('Translated :count label|Translated :count labels', count($translated)))
                    ->body(count($translated) < count($texts) ? __('The rest are still untranslated; try again for them.') : __('Look them over in the table and edit any that need it.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Labels the latest sync didn't find in the code (none before the first sync).
     *
     * @return Builder<LanguageLine>
     */
    protected function unusedQuery(): Builder
    {
        $latest = LanguageLine::query()->max('last_seen_at');

        return LanguageLine::query()->when(
            $latest === null,
            fn (Builder $query) => $query->whereRaw('0 = 1'),
            fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $latest)),
        );
    }
}
