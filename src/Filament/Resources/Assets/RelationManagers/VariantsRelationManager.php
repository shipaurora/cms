<?php

namespace Aurora\Filament\Resources\Assets\RelationManagers;

use Aurora\Filament\Concerns\ManagesImageFormats;
use Aurora\Models\Asset;
use Aurora\Models\ImageFormat;
use Aurora\Support\AssetVariants;
use Aurora\Support\ImageFormats;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * "Formats", the tab next to "Used on": every size the library makes from an image, and
 * the versions cropped or adjusted for a single block (Aurora\Support\AssetVariants). A list,
 * not a table, so it shows its own view.
 */
class VariantsRelationManager extends RelationManager
{
    use ManagesImageFormats;

    protected static string $relationship = 'media';

    protected static ?string $title = 'Formats';

    protected static string|\BackedEnum|null $icon = 'phosphor-stack';

    protected static bool $isLazy = false;

    /**
     * With the dialogs of its actions ("Image formats", "Regenerate").
     */
    protected string $view = 'aurora::filament.assets.formats-tab';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __(static::$title);
    }

    /**
     * Only images the library resizes have variants.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Asset && in_array($ownerRecord->mime_type, Asset::RASTER_MIME_TYPES, true);
    }

    public function table(Table $table): Table
    {
        return $table;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Actions::make([$this->formatsAction(), $this->regenerateFormatsAction(fn (): Asset => $this->getOwnerRecord(), __('Regenerate'))->size('sm')])->key('regenerate')->alignEnd(),
                View::make('aurora::filament.assets.variants')->viewData(['asset' => $this->getOwnerRecord()]),
            ]),
        ]);
    }

    /**
     * Make one format of this image again: on its row in the list.
     */
    public function regenerateFormatAction(): Action
    {
        $name = fn (array $arguments): string => collect(AssetVariants::sizes($this->getOwnerRecord()))->firstWhere('handle', $arguments['handle'] ?? null)['name'] ?? (string) ($arguments['handle'] ?? '');

        return Action::make('regenerateFormat')
            ->label(__('Regenerate'))
            ->icon('phosphor-arrows-clockwise')
            ->color('gray')
            ->iconButton()
            ->tooltip(__('Regenerate'))
            ->visible(fn (): bool => auth()->user()->can('update', $this->getOwnerRecord()))
            ->requiresConfirmation()
            ->modalIcon('phosphor-arrows-clockwise')
            ->modalHeading(fn (array $arguments): string => __('Regenerate “:name”?', ['name' => $name($arguments)]))
            ->modalDescription(__('This size is made again from the original.'))
            ->modalSubmitActionLabel(__('Regenerate'))
            ->action(function (array $arguments) use ($name): void {
                $handle = (string) ($arguments['handle'] ?? '');

                // Only the sizes the library makes: not the original, nor anything made up.
                if (! in_array($handle, ['thumb', 'og', ...ImageFormat::cached()->pluck('handle')], true)) {
                    return;
                }

                ImageFormats::regenerateImage($this->getOwnerRecord(), [$handle]);

                Notification::make()->title(__('“:name” regenerated', ['name' => $name($arguments)]))->success()->send();
            });
    }
}
