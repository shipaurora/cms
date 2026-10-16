<?php

namespace Aurora\Models;

use Aurora\Database\Factories\AuthenticatorAppFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An authenticator app (Google Authenticator, 1Password, Authy…) someone signs in with as a
 * second step: each has its own secret, so a lost phone is removed without the others.
 * See Aurora\Filament\Auth\AuthenticatorApps.
 */
#[Fillable(['name', 'secret', 'last_used_at'])]
#[Hidden(['secret'])]
class AuthenticatorApp extends Model
{
    /** @use HasFactory<AuthenticatorAppFactory> */
    use HasFactory;

    use HasUuids;

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
