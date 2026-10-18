<?php

namespace Aurora\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something that happened to the website's content, for the app to react to (clear a CDN,
 * notify another system) with an ordinary Laravel listener, and sent to webhooks
 * (Settings › Integrations › Webhooks) by its name.
 */
abstract class ContentEvent
{
    use Dispatchable;

    /**
     * Its name for webhooks ("page.published").
     */
    abstract public static function name(): string;

    /**
     * What webhooks are sent about it.
     *
     * @return array<string, mixed>
     */
    abstract public function payload(): array;
}
