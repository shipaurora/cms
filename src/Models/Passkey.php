<?php

namespace Aurora\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\LaravelPasskeys\Models\Passkey as SpatiePasskey;

class Passkey extends SpatiePasskey
{
    use HasUuids;
}
