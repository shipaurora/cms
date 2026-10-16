<?php

namespace Aurora\Filament\Resources\Forms\Pages;

use Aurora\Filament\Actions\LanguageSwitcher;
use Aurora\Filament\Concerns\BreadcrumbsToList;
use Aurora\Filament\Concerns\FollowsActiveLocale;
use Aurora\Filament\Concerns\SavesFromHeader;
use Aurora\Filament\Resources\Forms\FormResource;
use Aurora\Models\Form;
use Aurora\Support\ContentLocale;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

/**
 * @property Form $record
 */
class EditForm extends EditRecord
{
    use BreadcrumbsToList;
    use FollowsActiveLocale;
    use SavesFromHeader;
    use Translatable {
        Translatable::updatedActiveLocale as updatedTranslatableActiveLocale;
        FollowsActiveLocale::updatedActiveLocale insteadof Translatable;
    }

    protected static string $resource = FormResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    /**
     * The form's own fields, in a tab beside its submissions.
     */
    public function getContentTabLabel(): ?string
    {
        return __('Form');
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [
            LanguageSwitcher::make()->visible(fn (): bool => count(ContentLocale::all()) > 1),
            DeleteAction::make()->modalDescription(fn (): string => FormResource::deleteDescription($this->record)),
            $this->headerSaveButton(),
        ];
    }

    /**
     * Field text left empty falls back to the default site language: don't store it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['field_translations'] = collect($data['field_translations'] ?? [])
            ->map(fn (mixed $fields): array => collect((array) $fields)
                ->map(fn (mixed $text): array => array_filter((array) $text, fn (mixed $value): bool => filled($value)))
                ->filter()
                ->all())
            ->filter()
            ->all();

        return $data;
    }
}
