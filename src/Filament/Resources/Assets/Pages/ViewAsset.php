<?php

namespace Aurora\Filament\Resources\Assets\Pages;

use Aurora\Filament\Actions\LanguageSwitcher;
use Aurora\Filament\Resources\Assets\AssetResource;
use Aurora\Models\Asset;
use Aurora\Support\ContentLocale;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use LaraZeus\SpatieTranslatable\Resources\Pages\ViewRecord\Concerns\Translatable;

/**
 * A file for someone who may read it but not change it (AssetAccess): the same page as
 * editing it, read-only, with the file to download. Those who may change it get EditAsset.
 *
 * @property Asset $record
 */
class ViewAsset extends ViewRecord
{
    use Translatable;

    protected static string $resource = AssetResource::class;

    /**
     * Those who may change the file get its edit page, wherever they came from.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (AssetResource::canEdit($this->getRecord())) {
            $this->redirect(AssetResource::getUrl('edit', ['record' => $this->getRecord()]));
        }
    }

    public function bootedViewAsset(): void
    {
        ContentLocale::set($this->activeLocale ?? null);
    }

    public function updatedActiveLocale(): void
    {
        ContentLocale::set($this->activeLocale);
    }

    /**
     * Saying first that it can be looked at, not changed.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(__('You can view this file, but not change it'))
                ->description(__('Ask someone who can change it if it needs a new file, other details or different access.'))
                ->icon('phosphor-eye')
                ->info(),
            $this->getFormContentComponent(),
            $this->getRelationManagersContentComponent(),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->title;
    }

    /**
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
            AssetResource::downloadAction(fn (): Asset => $this->record),
        ];
    }
}
