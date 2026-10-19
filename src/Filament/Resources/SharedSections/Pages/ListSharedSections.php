<?php

namespace Aurora\Filament\Resources\SharedSections\Pages;

use Aurora\Filament\Concerns\WithoutBreadcrumbs;
use Aurora\Filament\Resources\SharedSections\SharedSectionResource;
use Aurora\Models\SharedSection;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSharedSections extends ListRecords
{
    use WithoutBreadcrumbs;

    protected static string $resource = SharedSectionResource::class;

    public function getSubheading(): ?string
    {
        return __('Sections built once and placed on many pages with the Shared section block. Change one here, and it changes everywhere.');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Named first, then built on the canvas.
            CreateAction::make()
                ->label(__('New shared section'))
                ->icon('phosphor-plus')
                ->modalHeading(__('New shared section'))
                ->modalIcon('phosphor-copy-simple')
                ->modalWidth('md')
                ->modalSubmitActionLabel(__('Continue'))
                ->createAnother(false)
                ->successRedirectUrl(fn (SharedSection $record): string => SharedSectionResource::getUrl('design', ['record' => $record])),
        ];
    }
}
