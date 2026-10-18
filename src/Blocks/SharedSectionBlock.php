<?php

namespace Aurora\Blocks;

use Aurora\Filament\Resources\SharedSections\SharedSectionResource;
use Aurora\Models\SharedSection;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

/**
 * A shared section (Aurora\Models\SharedSection) on this page: built once, shown wherever
 * it's placed, and changed for all of them in one place. The CMS's own block, whatever block
 * library the site uses.
 */
class SharedSectionBlock extends Block
{
    public static function type(): string
    {
        return 'shared-section';
    }

    public static function label(): string
    {
        return __('Shared section');
    }

    public static function description(): string
    {
        return __('A section kept in one place and shown on many pages: change it once, it changes everywhere.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-copy-simple';
    }

    public static function category(): string
    {
        return 'layout';
    }

    public static function view(): string
    {
        return 'aurora::blocks.shared-section';
    }

    public static function sameInEveryLanguage(): array
    {
        return ['shared_section_id'];
    }

    public static function schema(): array
    {
        return [
            Select::make('shared_section_id')
                ->label(__('Shared section'))
                ->options(fn (): array => SharedSection::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->live()
                ->helperText(__('Edit it under Content › Shared sections: it changes on every page that shows it.'))
                ->hintAction(fn (Get $get): ?Action => filled($get('shared_section_id')) ? Action::make('editSharedSection')
                    ->label(__('Edit'))
                    ->icon('phosphor-paint-brush')
                    ->url(SharedSectionResource::getUrl('design', ['record' => $get('shared_section_id')]), shouldOpenInNewTab: true) : null),
        ];
    }

    public static function aiHints(): ?string
    {
        return 'Places a shared section (see list_shared_sections) by its id in shared_section_id. Its content is edited where it is kept, not on this page.';
    }
}
