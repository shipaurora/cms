<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Aurora\Filament\Forms\Components\AssetPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Workbench\App\Blocks\Concerns\HasPreviewImage;

class ImageBlock extends Block
{
    use HasPreviewImage;

    public static function type(): string
    {
        return 'image';
    }

    public static function label(): string
    {
        return __('Image');
    }

    public static function description(): string
    {
        return __('A picture with a caption, aspect ratio and rounded corners.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-image';
    }

    public static function category(): string
    {
        return 'media';
    }

    public static function fileFields(): array
    {
        return [];
    }

    public static function defaults(): array
    {
        return ['ratio' => 'auto', 'rounded' => true, 'shadow' => false];
    }

    public static function editables(): array
    {
        return ['caption' => Editable::text()->placeholder(__('Add a caption'))];
    }

    public static function aiHints(): ?string
    {
        return 'src is a path on the public disk or an absolute https URL. Always provide alt text describing the image.';
    }

    public static function schema(): array
    {
        return [
            AssetPicker::make('src')->label(__('Image')),
            TextInput::make('alt')->label(__('Alternative text'))->helperText(__('Leave empty to use the alt text from the media library.')),
            TextInput::make('caption')->label(__('Caption')),
            ToggleButtons::make('ratio')->label(__('Aspect ratio'))->options(['auto' => __('Original'), '16/9' => '16:9', '4/3' => '4:3', '1/1' => __('Square'), '3/4' => __('Portrait')])->default('auto')->inline()->grouped(),
            Toggle::make('rounded')->label(__('Rounded corners'))->default(true),
            Toggle::make('shadow')->label(__('Drop shadow')),
        ];
    }
}
