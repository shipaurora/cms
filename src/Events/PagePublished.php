<?php

namespace Aurora\Events;

/**
 * A page went live, or its changes were published.
 */
class PagePublished extends PageEvent
{
    public static function name(): string
    {
        return 'page.published';
    }
}
