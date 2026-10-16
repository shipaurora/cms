<?php

namespace Aurora\Models;

use Aurora\Models\Concerns\BelongsToSite;
use Datlechin\FilamentMenuBuilder\Models\MenuLocation as BaseMenuLocation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MenuLocation extends BaseMenuLocation
{
    use BelongsToSite, HasUuids;
}
