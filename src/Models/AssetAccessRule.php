<?php

namespace Aurora\Models;

use Aurora\Enums\AccessLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of a file's or folder's own access: everyone (no grantee), a role or a person
 * may read it, or read and change it. See Aurora\Support\AssetAccess.
 */
#[Fillable(['grantee_type', 'grantee_id', 'level'])]
class AssetAccessRule extends Model
{
    use HasUuids;

    protected $table = 'asset_access';

    protected function casts(): array
    {
        return ['level' => AccessLevel::class];
    }

    public function accessible(): MorphTo
    {
        return $this->morphTo();
    }

    public function grantee(): MorphTo
    {
        return $this->morphTo();
    }
}
