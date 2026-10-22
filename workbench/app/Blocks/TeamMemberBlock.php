<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Aurora\Filament\Forms\Components\AssetPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Workbench\App\Blocks\Concerns\HasPreviewImage;

class TeamMemberBlock extends Block
{
    use HasPreviewImage;

    public static function type(): string
    {
        return 'team-member';
    }

    public static function label(): string
    {
        return __('Team member');
    }

    public static function description(): string
    {
        return __('A person with photo, role, short bio and contact links.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-user-circle';
    }

    public static function category(): string
    {
        return 'marketing';
    }

    public static function fileFields(): array
    {
        return [];
    }

    public static function defaults(): array
    {
        return ['name' => 'Full name', 'role' => 'Role', 'bio' => 'A sentence or two about what they do and love.'];
    }

    public static function editables(): array
    {
        return [
            'name' => Editable::text()->placeholder(__('Name')),
            'role' => Editable::text()->placeholder(__('Role')),
            'bio' => Editable::text()->multiline()->placeholder(__('Short bio')),
        ];
    }

    public static function schema(): array
    {
        return [
            AssetPicker::make('photo')->label(__('Photo')),
            Grid::make(2)->schema([
                TextInput::make('name')->label(__('Name')),
                TextInput::make('role')->label(__('Role')),
            ]),
            Textarea::make('bio')->label(__('Bio'))->rows(3),
            Grid::make(2)->schema([
                TextInput::make('email')->label(__('Email'))->email(),
                TextInput::make('linkedin')->label(__('LinkedIn URL'))->url(),
            ]),
        ];
    }
}
