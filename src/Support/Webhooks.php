<?php

namespace Aurora\Support;

use Aurora\Events\ContentEvent;
use Aurora\Events\FormSubmitted;
use Aurora\Events\PageDeleted;
use Aurora\Events\PagePublished;
use Aurora\Events\PageSaved;
use Aurora\Events\PageUnpublished;
use Aurora\Jobs\SendWebhook;
use Aurora\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Telling other systems what happens to the content: each content event (Aurora\Events) is
 * sent to the webhooks that want it, as JSON, on the queue so the admin never waits for
 * them. Each request is signed (X-Aurora-Signature: sha256=…, an HMAC of the body with the
 * webhook's secret), so the receiver can tell it came from here.
 */
class Webhooks
{
    /**
     * The events a webhook can be sent.
     *
     * @var array<int, class-string<ContentEvent>>
     */
    public const EVENTS = [PageSaved::class, PagePublished::class, PageUnpublished::class, PageDeleted::class, FormSubmitted::class];

    /**
     * Their names and what they mean, for choosing them.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'page.saved' => __('A page is saved'),
            'page.published' => __('A page goes live, or its changes are published'),
            'page.unpublished' => __('A live page is taken offline'),
            'page.deleted' => __('A page is moved to the trash'),
            'form.submitted' => __('A form is sent (not spam)'),
        ];
    }

    /**
     * Send an event to every webhook that wants it.
     */
    public static function send(ContentEvent $event): void
    {
        $payload = $event->payload();

        Webhook::query()->where('is_active', true)->get()
            ->filter(fn (Webhook $webhook): bool => in_array($event::name(), $webhook->events ?? [], true))
            ->each(fn (Webhook $webhook) => SendWebhook::dispatch($webhook->id, $event::name(), $payload));
    }

    /**
     * Send one request now, and remember how it went.
     *
     * @param  array<string, mixed>  $data
     */
    public static function deliver(?Webhook $webhook, string $event, array $data): bool
    {
        if ($webhook === null) {
            return false;
        }

        $body = (string) json_encode(['event' => $event, 'sent_at' => now()->toIso8601String(), 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Aurora-Event' => $event,
                    'X-Aurora-Signature' => 'sha256='.hash_hmac('sha256', $body, (string) $webhook->secret),
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);

            $webhook->forceFill(['last_status' => $response->status(), 'last_error' => $response->successful() ? null : 'HTTP '.$response->status(), 'last_sent_at' => now()])->save();

            return $response->successful();
        } catch (Throwable $exception) {
            $webhook->forceFill(['last_status' => null, 'last_error' => substr($exception->getMessage(), 0, 250), 'last_sent_at' => now()])->save();

            return false;
        }
    }
}
