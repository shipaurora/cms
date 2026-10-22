<?php

namespace Aurora\Filament\Forms\Components;

use Aurora\Enums\AssetType;
use Aurora\Filament\Resources\Assets\AssetResource;
use Aurora\Models\Asset;
use Aurora\Models\AssetFolder;
use Aurora\Models\AssetTag;
use Aurora\Support\AssetAccess;
use Aurora\Support\AssetLibrary;
use Aurora\Support\ImageEdits;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Pick one image (or several) from the media library, or upload a new one on the spot.
 * Image fields take images only; ->types() or ->anyFile() lets other files in, e.g. for
 * a link to a PDF.
 *
 * Stores asset ids, so a block keeps pointing at the same image when its file is
 * replaced or its alt text is corrected in the library.
 */
class AssetPicker extends Field
{
    protected string $view = 'aurora::filament.forms.components.asset-picker';

    protected bool $isMultiple = false;

    /**
     * @var array<int, AssetType>
     */
    protected array $types = [AssetType::Image];

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerActions([
            fn (AssetPicker $component): Action => $component->getPickAction(),
            fn (AssetPicker $component): Action => $component->getAdjustAction(),
        ]);
    }

    public function multiple(bool $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function isMultiple(): bool
    {
        return $this->isMultiple;
    }

    public function types(AssetType ...$types): static
    {
        $this->types = $types;

        return $this;
    }

    public function anyFile(): static
    {
        return $this->types(...AssetType::cases());
    }

    /**
     * @return array<int, AssetType>
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    public function takesOnlyImages(): bool
    {
        return $this->types === [AssetType::Image];
    }

    /**
     * The field's values as a list: each is a bare asset id or an id with this block's edits.
     *
     * @return array<int, mixed>
     */
    public function getValues(): array
    {
        $state = $this->getState();

        if ($state === null || $state === '' || $state === []) {
            return [];
        }

        return $this->isMultiple() && is_array($state) && array_is_list($state) ? $state : [$state];
    }

    /**
     * @return array<int, string>
     */
    public function getAssetIds(): array
    {
        return array_values(array_filter(array_map(fn (mixed $value): ?string => ImageEdits::assetId($value), $this->getValues())));
    }

    /**
     * Selected images with their per-block edits, in order.
     *
     * @return array<int, array{index: int, asset: Asset, edits: ImageEdits, preview: ?string}>
     */
    public function getItems(): array
    {
        $assets = Asset::query()->with('media')->whereIn('id', $this->getAssetIds())->get()->keyBy('id');
        $items = [];

        foreach ($this->getValues() as $index => $value) {
            $asset = $assets->get(ImageEdits::assetId($value));

            if ($asset !== null) {
                $items[] = ['index' => $index, 'asset' => $asset, 'edits' => ImageEdits::from($value), 'preview' => $asset->isImage() ? AssetLibrary::url($value, 'thumb') : null];
            }
        }

        return $items;
    }

    /**
     * A value that is not an asset id: a legacy path or URL, shown as-is.
     */
    public function getLegacyValue(): ?string
    {
        $state = $this->getState();

        return is_string($state) && $state !== '' && ImageEdits::assetId($state) === null ? $state : null;
    }

    #[ExposedLivewireMethod]
    public function removeAsset(int $index): void
    {
        $values = $this->getValues();
        unset($values[$index]);

        $this->state($this->isMultiple() ? array_values($values) : null);
        $this->callAfterStateUpdated();
        static::refreshCanvas($this);
    }

    public function getPickAction(): Action
    {
        return Action::make('pickAsset')
            ->label(fn (): string => match (true) {
                ! $this->takesOnlyImages() => $this->getState() ? __('Change') : __('Choose file'),
                $this->isMultiple() => __('Add images'),
                default => $this->getState() ? __('Change') : __('Choose image'),
            })
            ->icon(fn (): string => $this->takesOnlyImages() ? 'phosphor-image' : 'phosphor-file')
            ->color('gray')
            ->size('sm')
            ->modalHeading(fn (): string => match (true) {
                ! $this->takesOnlyImages() => __('Choose a file'),
                $this->isMultiple() => __('Add images'),
                default => __('Choose an image'),
            })
            ->modalDescription(fn (): string => $this->takesOnlyImages()
                ? __('Pick from the media library or upload a new image. Alt text and captions live with the image, so they follow it everywhere.')
                : __('Pick a file from the media library or upload a new one.'))
            ->modalWidth('5xl')
            ->modalSubmitActionLabel(fn (): string => match (true) {
                $this->isMultiple() => __('Add selected'),
                $this->takesOnlyImages() => __('Use image'),
                default => __('Use file'),
            })
            ->schema([
                Tabs::make()
                    ->contained(false)
                    ->tabs([
                        Tab::make(__('Library'))
                            ->icon('phosphor-stack')
                            ->schema([
                                Grid::make(['default' => 1, 'sm' => 4])->schema([
                                    TextInput::make('search')
                                        ->hiddenLabel()
                                        ->placeholder(fn (): string => $this->takesOnlyImages() ? __('Search all images…') : __('Search all files…'))
                                        ->prefixIcon('phosphor-magnifying-glass')
                                        ->live(debounce: 300)
                                        ->columnSpan(['sm' => 2]),
                                    FolderSelect::make('folder')
                                        ->hiddenLabel()
                                        ->placeholder(__('All folders'))
                                        ->live(),
                                    Select::make('tags')
                                        ->hiddenLabel()
                                        ->placeholder(__('All tags'))
                                        ->multiple()
                                        ->searchable()
                                        ->allowHtml()
                                        ->options(fn (): array => AssetTag::query()->orderBy('name')->get()->mapWithKeys(fn (AssetTag $tag): array => [$tag->id => AssetResource::tagLabel($tag)])->all())
                                        ->visible(fn (): bool => AssetTag::query()->exists())
                                        ->live(),
                                ]),
                                // Drop files on it to upload and pick them without switching tabs.
                                AssetGrid::make('selection')
                                    ->hiddenLabel()
                                    ->multiple($this->isMultiple())
                                    ->types($this->getTypes())
                                    ->viewData(fn (Get $get): array => [
                                        'assets' => static::search((string) $get('search'), $get('folder'), (array) ($get('tags') ?? []), $this->getTypes()),
                                    ])
                                    ->default($this->isMultiple() ? [] : null),
                            ]),
                        Tab::make(__('Upload'))
                            ->icon('phosphor-upload-simple')
                            ->schema([
                                FileUpload::make('uploads')
                                    ->hiddenLabel()
                                    ->acceptedFileTypes(array_merge(...array_map(fn (AssetType $type): array => $type->mimeTypes(), $this->getTypes())))
                                    ->multiple($this->isMultiple())
                                    ->storeFiles(false)
                                    ->maxSize($this->takesOnlyImages() ? AssetType::maxImageKilobytes() : AssetType::maxFileKilobytes()),
                                TextInput::make('alt')
                                    ->label(__('Alt text'))
                                    ->helperText(__('Describe the image for people using screen readers. Saved with the image in the library.'))
                                    ->visible(! $this->isMultiple() && $this->takesOnlyImages()),
                            ]),
                    ]),
            ])
            ->action(function (array $data, AssetPicker $component): void {
                $chosen = array_values(array_filter(Arr::wrap($data['selection'] ?? []), 'is_string'));

                // A single upload is one file object, not a list: wrap it, never cast it.
                foreach (Arr::wrap($data['uploads'] ?? []) as $upload) {
                    if ($upload instanceof TemporaryUploadedFile && AssetLibrary::rejectionFor($upload) === null && in_array(AssetType::fromMimeType($upload->getMimeType()), $component->getTypes(), true)) {
                        // Into the folder being browsed, if they may add files there; otherwise the top level.
                        $folder = filled($data['folder'] ?? null) ? AssetFolder::query()->find($data['folder']) : null;
                        $chosen[] = AssetLibrary::fromUpload($upload, ['alt' => $data['alt'] ?? null, 'folder_id' => AssetAccess::canWrite(auth()->user(), $folder) ? $folder?->id : null])->id;
                    }
                }

                if ($chosen === []) {
                    return;
                }

                $component->state($component->isMultiple()
                    ? [...$component->getValues(), ...array_values(array_diff($chosen, $component->getAssetIds()))]
                    : end($chosen));

                $component->callAfterStateUpdated();
                static::refreshCanvas($component);
            });
    }

    /**
     * Crop, rotate, flip and tone for this block only. The library original is untouched,
     * so the same image can be framed differently wherever it is used.
     */
    public function getAdjustAction(): Action
    {
        return Action::make('adjustAsset')
            ->label(__('Adjust'))
            ->icon('phosphor-sliders-horizontal')
            ->color('gray')
            ->size('sm')
            ->modalHeading(__('Adjust for this block'))
            ->modalDescription(__('Changes apply only here. The original in the media library, and every other block using it, stay as they are.'))
            ->modalWidth('6xl')
            ->modalSubmitActionLabel(__('Apply'))
            ->fillForm(function (array $arguments): array {
                $value = $this->getValues()[(int) ($arguments['index'] ?? 0)] ?? null;

                return ['edits' => is_array($value) ? $value : ['asset' => $value]];
            })
            ->schema(fn (array $arguments): array => [
                ViewField::make('edits')
                    ->hiddenLabel()
                    ->view('aurora::filament.forms.components.image-editor')
                    ->viewData([
                        'src' => AssetLibrary::find($this->getValues()[(int) ($arguments['index'] ?? 0)] ?? null)?->url('lg'),
                    ]),
            ])
            ->action(function (array $data, array $arguments, AssetPicker $component): void {
                $index = (int) ($arguments['index'] ?? 0);
                $values = $component->getValues();
                $assetId = ImageEdits::assetId($values[$index] ?? null);

                if ($assetId === null) {
                    return;
                }

                $values[$index] = ImageEdits::from($data['edits'] ?? [])->toValue($assetId);

                $component->state($component->isMultiple() ? $values : $values[$index]);
                $component->callAfterStateUpdated();
                static::refreshCanvas($component);
            });
    }

    /**
     * @param  array<int, string>  $tags  Images with any of these tags.
     * @param  array<int, AssetType>  $types  Image fields take images only.
     * @return Collection<int, Asset>
     */
    public static function search(string $search, ?string $folder = null, array $tags = [], array $types = [AssetType::Image]): Collection
    {
        $folder = $folder ? AssetFolder::query()->find($folder) : null;

        return Asset::query()
            ->tap(fn ($query) => AssetAccess::scopeReadable($query, auth()->user()))
            ->with('media')
            // Image fields take images only: no PDFs or MP3s, whatever else the library holds.
            ->ofType(...$types)
            // A folder shows its own images and those in its subfolders.
            ->when($folder, fn ($query) => $query->whereIn('folder_id', $folder->descendantIds()))
            ->when($search !== '', fn ($query) => $query->search($search))
            ->when($tags !== [], fn ($query) => $query->whereHas('tags', fn ($query) => $query->whereKey($tags)))
            ->latest()
            ->limit(60)
            ->get();
    }

    /**
     * The visual editor commits the inspector to the block on Livewire updates from the
     * browser; a change made inside an action happens server-side, so commit it here.
     */
    protected static function refreshCanvas(AssetPicker $component): void
    {
        $livewire = $component->getLivewire();

        if (method_exists($livewire, 'commitSelectedBlock')) {
            $livewire->commitSelectedBlock();
        }
    }
}
