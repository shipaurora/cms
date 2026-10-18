<?php

namespace Aurora\Models;

use Aurora\Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An address told about what happens to the content (Aurora\Support\Webhooks), set up in
 * Settings › Integrations › Webhooks.
 */
#[Fillable(['url', 'events', 'secret', 'is_active', 'last_status', 'last_error', 'last_sent_at'])]
#[Hidden(['secret'])]
class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $webhook) => $webhook->secret ??= 'whsec_'.Str::random(32));
    }

    /**
     * Whether its last request went through.
     */
    public function lastSucceeded(): ?bool
    {
        return $this->last_sent_at === null ? null : ($this->last_status !== null && $this->last_status < 300);
    }
}
