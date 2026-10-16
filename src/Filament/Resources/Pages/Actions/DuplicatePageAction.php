<?php

namespace Aurora\Filament\Resources\Pages\Actions;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Page;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\TextInput;

/**
 * A draft copy of a page with the same content and translations, its own addresses (made
 * from its titles) and the person duplicating it as author. The copy opens once made.
 * On the page's edit page and in the page list.
 */
class DuplicatePageAction extends ReplicateAction
{
    public static function getDefaultName(): ?string
    {
        return 'replicate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Duplicate'))
            ->icon('phosphor-copy')
            ->visible(fn (Page $record): bool => ! $record->trashed() && (bool) auth()->user()?->can('create', Page::class))
            ->modalIcon('phosphor-copy')
            ->modalHeading(fn (Page $record): string => __('Duplicate “:title”', ['title' => $record->title]))
            ->modalDescription(__('The copy starts as a draft, with the same content, layout and translations. Its history starts fresh.'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('Duplicate'))
            ->schema([
                TextInput::make('title')
                    ->label(__('Title of the copy'))
                    ->required()
                    ->maxLength(255)
                    ->autofocus(),
            ])
            ->fillForm(fn (Page $record): array => ['title' => __(':title (copy)', ['title' => $record->title])])
            ->excludeAttributes(['slug', 'is_homepage', 'published_at', 'status', 'author_id', 'last_edited_by_id'])
            ->beforeReplicaSaved(function (Page $replica): void {
                $replica->status = PageStatus::Draft;
                $replica->author_id = auth()->id();
            })
            ->successNotificationTitle(__('Page duplicated'))
            ->successRedirectUrl(fn (Page $replica): string => PageResource::getUrl('edit', ['record' => $replica]));
    }
}
