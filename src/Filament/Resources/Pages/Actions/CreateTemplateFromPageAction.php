<?php

namespace Aurora\Filament\Resources\Pages\Actions;

use Aurora\Filament\Resources\PageTemplates\PageTemplateResource;
use Aurora\Models\Page;
use Aurora\Support\PageTemplates;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * A page template made from a page as it is now: its layout, the layout's fields, its blocks
 * and their translations (Aurora\Support\PageTemplates::fromPage()). The page and the template
 * go their own ways after. On the page's edit page and in the page list, for those who manage
 * page templates.
 */
class CreateTemplateFromPageAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createTemplateFromPage';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Create template from this page'))
            ->icon('phosphor-files')
            ->visible(fn (Page $record): bool => ! $record->trashed() && Page::storesTemplates() && (bool) auth()->user()?->can('manage-templates Page'))
            ->modalIcon('phosphor-files')
            ->modalHeading(fn (Page $record): string => __('Create a template from “:title”', ['title' => $record->title]))
            ->modalDescription(__('New pages can start from it: this page’s layout and blocks, ready to fill in. Changing either later doesn’t change the other.'))
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('Create template'))
            ->fillForm(fn (Page $record): array => ['name' => $record->title])
            ->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(80)
                    ->autofocus(),
                TextInput::make('description')
                    ->label(__('Description'))
                    ->helperText(__('Shown with the template when starting a new page.'))
                    ->maxLength(160),
            ])
            ->action(function (array $data, Page $record): void {
                $template = PageTemplates::fromPage($record, $data['name'], $data['description'] ?? null);

                Notification::make()
                    ->title(__('Created the page template “:name”', ['name' => $template->title]))
                    ->actions([Action::make('design')->label(__('Design it'))->url(PageTemplateResource::getUrl('design', ['record' => $template]))])
                    ->success()
                    ->send();
            });
    }
}
