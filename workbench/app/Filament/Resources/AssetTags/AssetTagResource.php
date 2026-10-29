<?php

namespace Workbench\App\Filament\Resources\AssetTags;

use Aurora\Models\AssetTag;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Filament\Resources\AssetTags\Pages\ListAssetTags;

class AssetTagResource extends Resource
{
    protected static ?string $model = AssetTag::class;

    protected static ?string $slug = 'plugin-tags';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAssetTags::route('/')];
    }
}
