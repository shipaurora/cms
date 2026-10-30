<?php

namespace Workbench\App\Models;

use Aurora\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * A website's own model with a policy (GadgetPolicy), for the record tools' tests.
 */
class Gadget extends Model
{
    use BelongsToSite;

    protected $fillable = ['name', 'status', 'notes', 'api_key'];

    protected $hidden = ['notes'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }
}
