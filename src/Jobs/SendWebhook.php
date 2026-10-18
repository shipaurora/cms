<?php

namespace Aurora\Jobs;

use Aurora\Models\Webhook;
use Aurora\Support\Webhooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One content event sent to one webhook, on the queue so the admin never waits for it
 * (Aurora\Support\Webhooks). Tried again a little later when the receiver isn't reachable.
 */
class SendWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public string $webhookId, public string $event, public array $data) {}

    public function handle(): void
    {
        Webhooks::deliver(Webhook::query()->find($this->webhookId), $this->event, $this->data);
    }
}
