<?php

namespace Workbench\App\Resources;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Workbench\App\Models\Gadget;

/**
 * A website's resource for its own model, whose form the record tools read. Registered by the
 * test that needs it, not discovered with the workbench's other resources.
 */
class GadgetResource extends Resource
{
    protected static ?string $model = Gadget::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Gadget name')->required(),
            Select::make('status')->options(['draft' => 'Draft', 'live' => 'Live', 'retired' => 'Retired']),
        ]);
    }
}
