<?php

namespace Aurora\Filament\Concerns;

use Aurora\Enums\ImageFileFormat;
use Aurora\Models\Asset;
use Aurora\Models\ImageFormat;
use Aurora\Support\ImageFormats;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The "Image formats" dialog, opened from the media library's "More" menu and from an
 * image's Formats tab, and making an image's formats again.
 */
trait ManagesImageFormats
{
    /**
     * The sizes the library makes of every image, one row each. Original, Thumbnail and Share
     * image are built in; the others (Small, Medium and Large to start with) are changed one at
     * a time in a dialog of their own, and more can be added. Each change saves straight away
     * and brings existing images up to date in the background.
     */
    protected function formatsAction(): Action
    {
        return Action::make('formats')
            ->label(__('Image formats'))
            ->icon('phosphor-frame-corners')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()->can('manage-settings'))
            ->modalHeading(__('Image formats'))
            ->modalDescription(__('The sizes the library makes of every image. Blocks pick the one that fits, and responsive formats let browsers choose the size that suits the screen.'))
            ->modalIcon('phosphor-frame-corners')
            ->modalWidth('2xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->schema(fn (): array => $this->formatRows());
    }

    /**
     * The built-in sizes, a row for each format and "Add format": in the dialog, and in
     * Settings › Image formats.
     *
     * @return array<int, Component>
     */
    protected function formatRows(): array
    {
        return [
            View::make('aurora::filament.assets.built-in-formats'),
            Group::make(ImageFormat::cached()->map(fn (ImageFormat $format): Flex => Flex::make([
                Group::make([
                    Text::make(new HtmlString(e($format->name).' <span class="cms-format-handle">('.e($format->handle).')</span>'))->weight(FontWeight::Medium)->color('neutral'),
                    Text::make(implode(' · ', [$format->describe(), $format->file_format->getLabel(), __(':quality% quality', ['quality' => $format->quality])]))->size('sm'),
                ]),
                Text::make(__('Responsive'))->badge()->color('primary')->visible($format->is_responsive)->grow(false),
                Actions::make([
                    $this->editFormatAction($format),
                    $this->deleteFormatAction($format),
                ])->key('format-'.$format->id)->grow(false),
            ])->verticallyAlignCenter()->extraAttributes(['class' => 'cms-format-row']))->all())
                ->extraAttributes(['class' => 'cms-format-list']),
            Actions::make([$this->editFormatAction(null)])->key('add-format'),
        ];
    }

    /**
     * One format, in a dialog of its own: added (no $format) or changed.
     */
    protected function editFormatAction(?ImageFormat $format): Action
    {
        $isNew = $format === null;

        return Action::make($isNew ? 'addFormat' : 'editFormat')
            ->label($isNew ? __('Add format') : __('Edit'))
            ->icon($isNew ? 'phosphor-plus-bold' : 'phosphor-pencil-simple')
            ->color('gray')
            ->when(! $isNew, fn (Action $action): Action => $action->iconButton())
            // Over the "Image formats" dialog, which stays open behind it and shows the change.
            ->overlayParentActions()
            ->after(fn () => $this->refreshFormatsDialog())
            ->modalHeading($isNew ? __('Add a format') : __('Edit “:name”', ['name' => $format->name]))
            ->modalDescription($isNew ? __('A new size the library makes of every image.') : __('Changing the size or file type makes this format again for every image.'))
            ->modalIcon('phosphor-frame-corners')
            ->modalWidth('lg')
            ->modalSubmitActionLabel($isNew ? __('Add format') : __('Save'))
            ->fillForm(fn (): array => $isNew
                ? ['file_format' => ImageFileFormat::Webp->value, 'quality' => 82, 'is_responsive' => true]
                : [
                    'name' => $format->name,
                    'handle' => $format->handle,
                    'width' => $format->width,
                    'height' => $format->height,
                    'file_format' => $format->file_format->value,
                    'quality' => $format->quality,
                    'is_responsive' => $format->is_responsive,
                ])
            ->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->placeholder(__('e.g. Extra large'))
                    ->required()
                    ->maxLength(60)
                    // A new format's handle follows its name until it's typed in.
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Get $get, Set $set) => $isNew && blank($get('handle')) ? $set('handle', Str::slug((string) $state)) : null),
                TextInput::make('handle')
                    ->label(__('Handle'))
                    ->prefixIcon('phosphor-code')
                    ->helperText($isNew ? __('What code asks for this size by. It can’t be changed later.') : __('What code asks for this size by. It can’t be changed.'))
                    ->required($isNew)
                    ->disabled(! $isNew)
                    ->maxLength(30)
                    // Checked when it's chosen: an existing format keeps the one it has.
                    ->when($isNew, fn (TextInput $input): TextInput => $input
                        ->regex('/^[a-z0-9][a-z0-9-]*$/')
                        ->notIn(ImageFormat::RESERVED_HANDLES)
                        ->unique(ImageFormat::class, 'handle'))
                    ->validationMessages([
                        'regex' => __('Use lowercase letters, numbers and dashes.'),
                        'not_in' => __('Thumbnail, Share image and the original already use this name.'),
                    ]),
                Fieldset::make(__('Size'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('width')->label(__('Width'))->required()->integer()->minValue(16)->maxValue(6000)->suffix('px'),
                        TextInput::make('height')->label(__('Height'))->integer()->minValue(16)->maxValue(6000)->suffix('px')->placeholder(__('Auto')),
                        Text::make(__('Leave the height empty to keep each image’s proportions. With a height, images are cropped to exactly this size.'))->size('sm')->columnSpanFull(),
                    ]),
                Fieldset::make(__('File'))
                    ->columns(1)
                    ->schema([
                        ToggleButtons::make('file_format')->label(__('File type'))->options(ImageFileFormat::class)->required()->inline()->grouped(),
                        TextInput::make('quality')->label(__('Quality'))->required()->integer()->minValue(1)->maxValue(100)->suffix('%')
                            ->helperText(__('Lower makes smaller files; around 80 looks the same to most eyes.')),
                    ]),
                Toggle::make('is_responsive')
                    ->label(__('Use for responsive images'))
                    ->helperText(__('Browsers pick the responsive size that suits the screen.')),
            ])
            ->action(function (array $data) use ($format, $isNew): void {
                $result = ImageFormats::put($format, $data);

                Notification::make()
                    ->title($isNew ? __('Added “:name”', ['name' => $data['name']]) : __('Saved “:name”', ['name' => $data['name']]))
                    ->body($result['changed'] !== [] ? __('Existing images are being updated to match.') : null)
                    ->success()
                    ->send();
            });
    }

    /**
     * Build the list again so it shows the change: the "Image formats" dialog behind a
     * format's dialog, or the page's form where the list is part of it (Settings).
     */
    protected function refreshFormatsDialog(): void
    {
        $index = array_key_last($this->mountedActions) - 1;

        if ($index >= 0) {
            $this->cacheSchema("mountedActionSchema{$index}", fn (): ?Schema => $this->getMountedActionSchema($index));
        } else {
            unset($this->cachedSchemas['form']);
        }
    }

    protected function deleteFormatAction(ImageFormat $format): Action
    {
        return Action::make('deleteFormat')
            ->label(__('Delete'))
            ->icon('phosphor-trash')
            ->color('danger')
            ->iconButton()
            ->overlayParentActions()
            ->after(fn () => $this->refreshFormatsDialog())
            ->requiresConfirmation()
            ->modalHeading(__('Delete “:name”?', ['name' => $format->name]))
            ->modalDescription(__('Images lose this size. Anything asking for it gets the largest responsive size instead.'))
            ->action(function () use ($format): void {
                ImageFormats::remove($format);

                Notification::make()->title(__('Deleted “:name”', ['name' => $format->name]))->success()->send();
            });
    }

    /**
     * Make every format of an image again: after its formats changed, or when a file is
     * missing or looks wrong. On the image's Formats tab and in its page's "More" menu.
     *
     * @param  Closure(): Asset  $asset
     */
    protected function regenerateFormatsAction(Closure $asset, string $label): Action
    {
        return Action::make('regenerateFormats')
            ->label($label)
            ->icon('phosphor-arrows-clockwise')
            ->color('gray')
            ->visible(fn (): bool => in_array($asset()->mime_type, Asset::RASTER_MIME_TYPES, true) && auth()->user()->can('update', $asset()))
            ->requiresConfirmation()
            ->modalIcon('phosphor-arrows-clockwise')
            ->modalHeading(__('Regenerate every format of this image?'))
            ->modalDescription(__('Every size is made again from the original, and the versions edited for blocks are made again the next time they’re shown.'))
            ->modalSubmitActionLabel(__('Regenerate'))
            ->action(function () use ($asset): void {
                ImageFormats::regenerateImage($asset());

                Notification::make()->title(__('Formats regenerated'))->success()->send();
            });
    }
}
