<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Aurora\Filament\Forms\Components\AssetPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Workbench\App\Blocks\Concerns\HasPreviewImage;

class LogoCloudBlock extends Block
{
    use HasPreviewImage;

    public static function type(): string
    {
        return 'logo-cloud';
    }

    public static function label(): string
    {
        return __('Logo cloud');
    }

    public static function description(): string
    {
        return __('Customer or partner logos, optionally scrolling as a marquee.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-buildings';
    }

    public static function category(): string
    {
        return 'marketing';
    }

    public static function defaults(): array
    {
        return [
            'title' => 'Trusted by teams at',
            'marquee' => true,
            'logos' => array_map(fn (string $name): array => ['name' => $name], ['Fjordline', 'Nordvik', 'Polar Labs', 'Skyline', 'Havbris', 'Tindra']),
        ];
    }

    public static function editables(): array
    {
        return ['title' => Editable::text()->placeholder(__('Trusted by…'))];
    }

    public static function itemEditables(): array
    {
        return ['logos' => ['name' => Editable::text()->placeholder(__('Company'))]];
    }

    public static function aiHints(): ?string
    {
        return 'logos is a list of {name, image?}; without an image the company name is set as a wordmark.';
    }

    public static function schema(): array
    {
        return [
            TextInput::make('title')->label(__('Title')),
            Toggle::make('marquee')->label(__('Scroll continuously'))->default(true),
            Repeater::make('logos')
                ->label(__('Logos'))
                ->schema([
                    TextInput::make('name')->label(__('Company'))->required(),
                    AssetPicker::make('image')->label(__('Logo (optional)')),
                ])
                ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                ->collapsed()
                ->addActionLabel(__('Add logo')),
        ];
    }
}
