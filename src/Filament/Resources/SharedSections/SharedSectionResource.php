<?php

namespace Aurora\Filament\Resources\SharedSections;

use Aurora\Filament\Concerns\TranslatesResourceLabels;
use Aurora\Filament\Resources\SharedSections\Pages\DesignSharedSection;
use Aurora\Filament\Resources\SharedSections\Pages\ListSharedSections;
use Aurora\Models\SharedSection;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Content › Shared sections: sections built once and shown on many pages (the "Shared
 * section" block), each edited on the canvas like a page.
 */
class SharedSectionResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = SharedSection::class;

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-copy-simple';

    protected static string|BackedEnum|null $activeNavigationIcon = 'phosphor-copy-simple-bold';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->placeholder(__('e.g. Contact strip'))
                ->helperText(__('What editors pick it by when placing it on a page.'))
                ->columnSpanFull()
                ->required()
                ->maxLength(120),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (SharedSection $record): string => static::getUrl('design', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('pages')
                    ->label(__('Shown on'))
                    ->state(fn (SharedSection $record): string => trans_choice(':count page|:count pages', $record->pages()->count()))
                    ->color('gray'),
                TextColumn::make('updated_at')
                    ->label(__('Changed'))
                    ->since()
                    ->color('gray')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                ActionGroup::make([
                    Action::make('design')
                        ->label(__('Open visual editor'))
                        ->icon('phosphor-paint-brush')
                        ->url(fn (SharedSection $record): string => static::getUrl('design', ['record' => $record])),
                    EditAction::make()->label(__('Rename'))->icon('phosphor-pencil-simple')->modalHeading(__('Rename shared section'))->modalWidth('md'),
                    DeleteAction::make()
                        ->modalDescription(fn (SharedSection $record): string => ($count = $record->pages()->count()) > 0
                            ? trans_choice('It’s on :count page, which then shows nothing in its place.|It’s on :count pages, which then show nothing in its place.', $count)
                            : __('No page shows it.')),
                ]),
            ])
            ->emptyStateIcon('phosphor-copy-simple')
            ->emptyStateHeading(__('No shared sections yet'))
            ->emptyStateDescription(__('Build a section once, a call to action or a contact strip, and place it on any page with the Shared section block.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSharedSections::route('/'),
            'design' => DesignSharedSection::route('/{record}/design'),
        ];
    }
}
