<?php

namespace Aurora\Models;

use Aurora\Notifications\InvitedToCms;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * An invitation for someone to join the CMS with a given role.
 *
 * The link is a temporary signed URL, so no secret is stored: the signature (made with the
 * app key) proves the link was issued here, the expiry date limits it to a week, and
 * accepted_at makes it single-use. Revoking deletes the row, which kills the link.
 */
#[Fillable(['email', 'role_id', 'message', 'invited_by_id', 'expires_at'])]
class Invitation extends Model
{
    use HasUuids;

    public const VALID_FOR_DAYS = 7;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('accepted_at');
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    public function url(): string
    {
        return URL::temporarySignedRoute('filament.admin.invitations.accept', $this->expires_at, ['invitation' => $this->getKey()]);
    }

    /**
     * Send (or send again) the invitation email, with a fresh week of validity.
     */
    public function send(): void
    {
        $this->forceFill(['expires_at' => now()->addDays(self::VALID_FOR_DAYS), 'sent_at' => now()])->save();

        Notification::route('mail', $this->email)->notify(new InvitedToCms($this));
    }
}
