<?php

namespace Aurora\Models;

use Aurora\Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A key an app reads the website's APIs with, REST and GraphQL alike (Aurora\Support\PublicApi),
 * made and revoked in Settings › Security › API keys. Only its hash is kept.
 */
#[Fillable(['name', 'key_hash', 'hint', 'created_by_id', 'last_used_at'])]
#[Hidden(['key_hash'])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * A new key: the record, and the key itself, to show the person once.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, ?User $by = null): array
    {
        $key = 'aurora_'.Str::random(40);

        $record = static::query()->create([
            'name' => $name,
            'key_hash' => static::hash($key),
            'hint' => Str::limit($key, 11, ''),
            'created_by_id' => $by?->getKey(),
        ]);

        return [$record, $key];
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
