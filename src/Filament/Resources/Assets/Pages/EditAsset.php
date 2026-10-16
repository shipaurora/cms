<?php

namespace Aurora\Filament\Resources\Assets\Pages;

use Aurora\Enums\AssetType;
use Aurora\Filament\Actions\AccessAction;
use Aurora\Filament\Actions\LanguageSwitcher;
use Aurora\Filament\Concerns\FollowsActiveLocale;
use Aurora\Filament\Concerns\ManagesImageFormats;
use Aurora\Filament\Concerns\SavesFromHeader;
use Aurora\Filament\Resources\Assets\AssetResource;
use Aurora\Models\Asset;
use Aurora\Support\AssetLibrary;
use Aurora\Support\ContentLocale;
use Aurora\Support\Renditions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

/**
 * @property Asset $record
 */
class EditAsset extends EditRecord
{
    use FollowsActiveLocale;
    use ManagesImageFormats;
    use SavesFromHeader;
    use Translatable {
        Translatable::updatedActiveLocale as updatedTranslatableActiveLocale;
        FollowsActiveLocale::updatedActiveLocale insteadof Translatable;
    }

    protected static string $resource = AssetResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->title;
    }

    /**
     * Where the image lives: Library › its folders (the
     * title below names the image).
     * Each folder opens that folder in the library.
     *
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return AssetResource::breadcrumbsFor($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            LanguageSwitcher::make()->visible(fn (): bool => count(ContentLocale::all()) > 1),
            ActionGroup::make([
                Action::make('replace')
                    ->label(__('Replace file'))
                    ->icon('phosphor-arrows-clockwise')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()->can('update', $this->record))
                    ->modalDescription(fn (): string => trans_choice('Every page using this file shows the new one right away.|Every page using this file shows the new one right away (:count places).', $this->record->usages()->count() > 1 ? $this->record->usages()->count() : 1))
                    ->schema([
                        // The same kind of file: pages show an image as an image, a video as a video.
                        FileUpload::make('file')
                            ->hiddenLabel()
                            ->acceptedFileTypes(fn (): array => $this->record->type()->mimeTypes() ?: AssetType::allowedMimeTypes())
                            ->storeFiles(false)
                            ->maxSize(fn (): int => $this->record->type()->maxKilobytes())
                            ->required(),
                    ])
                    ->action(function (array $data, Action $action): void {
                        $type = $this->record->type();

                        if ($rejection = AssetLibrary::rejectionFor($data['file'], $type === AssetType::Other ? null : $type)) {
                            Notification::make()->title(__('The file wasn’t replaced'))->body($rejection)->danger()->send();
                            $action->halt();
                        }

                        $size = AssetLibrary::imageSize($data['file']->getRealPath());

                        Renditions::purge($this->record);

                        $media = $this->record
                            ->addMedia($data['file'])
                            ->withCustomProperties(array_filter(['width' => $size[0] ?? null, 'height' => $size[1] ?? null]))
                            ->toMediaCollection(Asset::COLLECTION);

                        // The new file goes to the current disk: the asset moves there with it.
                        $this->record->update(['mime_type' => $media->mime_type, 'disk' => $media->disk]);

                        Notification::make()->title(__('File replaced'))->success()->send();
                        $this->redirect(AssetResource::getUrl('edit', ['record' => $this->record]));
                    }),
                AssetResource::downloadAction(fn (): Asset => $this->record),
                $this->regenerateFormatsAction(fn (): Asset => $this->record, __('Regenerate formats')),
                // Last, set apart.
                ActionGroup::make([AccessAction::make('access', fn (): Asset => $this->record)])->dropdown(false),
            ])
                ->label(__('More'))
                ->icon('phosphor-dots-three-vertical')
                ->button()
                ->color('gray'),
            DeleteAction::make()
                ->modalDescription(fn (): string => ($count = $this->record->usages()->count()) > 0
                    ? trans_choice('This file is used in :count place. That spot will be left empty.|This file is used in :count places. Those spots will be left empty.', $count)
                    : __('It is not used anywhere.')),
            $this->headerSaveButton(),
        ];
    }
}
