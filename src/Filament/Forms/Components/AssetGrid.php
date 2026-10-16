<?php

namespace Aurora\Filament\Forms\Components;

use Aurora\Enums\AssetType;
use Aurora\Support\AssetLibrary;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Support\Arr;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The asset picker's library grid: pick from it, or drop files on it to add them to the
 * library and select them, ready to confirm (resources/views/filament/forms/components/asset-grid.blade.php).
 */
class AssetGrid extends ViewField
{
    protected string $view = 'aurora::filament.forms.components.asset-grid';

    protected bool $isMultiple = false;

    /**
     * @var array<int, AssetType>
     */
    protected array $types = [AssetType::Image];

    public function multiple(bool $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function isMultiple(): bool
    {
        return $this->isMultiple;
    }

    /**
     * @param  array<int, AssetType>  $types
     */
    public function types(array $types): static
    {
        $this->types = $types;

        return $this;
    }

    /**
     * @return array<int, AssetType>
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * Where dropped files are uploaded to, beside the selection.
     */
    public function getDroppedStatePath(): string
    {
        return $this->getStatePath().'_dropped';
    }

    /**
     * The files just dropped on the grid (uploaded to getDroppedStatePath()), added to the
     * library in the folder being browsed and selected: one image replaces the selection,
     * several are added to it. Files the picker doesn't take are reported, not stored.
     *
     * @return array<int, string> the new assets' ids
     */
    #[ExposedLivewireMethod]
    public function storeDroppedFiles(): array
    {
        $livewire = $this->getLivewire();
        $uploads = Arr::wrap(data_get($livewire, $this->getDroppedStatePath()));
        data_set($livewire, $this->getDroppedStatePath(), []);

        $folder = data_get($livewire, $this->getContainer()->getStatePath().'.folder');
        $stored = [];

        foreach ($uploads as $upload) {
            if (! $upload instanceof TemporaryUploadedFile) {
                continue;
            }

            $problem = AssetLibrary::rejectionFor($upload)
                ?? (in_array(AssetType::fromMimeType($upload->getMimeType()), $this->getTypes(), true) ? null : __('This kind of file can\'t be used here.'));

            if ($problem !== null) {
                Notification::make()->title($upload->getClientOriginalName())->body($problem)->danger()->send();

                continue;
            }

            $stored[] = AssetLibrary::fromUpload($upload, ['folder_id' => is_string($folder) ? $folder : null])->id;
        }

        if ($stored !== []) {
            $this->state($this->isMultiple()
                ? array_values(array_unique([...Arr::wrap($this->getState() ?? []), ...$stored]))
                : end($stored));
        }

        return $stored;
    }
}
