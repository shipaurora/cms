<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Aurora\Models\Page;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;

class PageListBlock extends Block
{
    public static function type(): string
    {
        return 'page-list';
    }

    public static function label(): string
    {
        return __('Page list');
    }

    public static function description(): string
    {
        return __('Live cards of other pages. Always up to date: titles and links follow the pages.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-newspaper';
    }

    public static function category(): string
    {
        return 'dynamic';
    }

    public static function defaults(): array
    {
        return ['source' => 'latest', 'limit' => '3', 'link_label' => 'Read more'];
    }

    public static function editables(): array
    {
        return ['link_label' => Editable::text()->placeholder(__('Read more'))];
    }

    public static function aiHints(): ?string
    {
        return 'source "latest" shows the most recently published pages; source "selected" shows page_ids (a list of page ids) in that order. Only published pages are shown to visitors.';
    }

    public static function schema(): array
    {
        return [
            ToggleButtons::make('source')->label(__('Show'))->options(['latest' => __('Latest pages'), 'selected' => __('Hand-picked')])->default('latest')->inline()->grouped()->live(),
            ToggleButtons::make('limit')->label(__('How many'))->options(['2' => '2', '3' => '3', '4' => '4', '6' => '6'])->default('3')->inline()->grouped()
                ->visible(fn (Get $get): bool => $get('source') !== 'selected'),
            Select::make('page_ids')->label(__('Pages'))->multiple()->searchable()
                ->options(fn (): array => Page::options())
                ->visible(fn (Get $get): bool => $get('source') === 'selected'),
            TextInput::make('link_label')->label(__('Link label')),
        ];
    }
}
