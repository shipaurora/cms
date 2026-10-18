<?php

namespace Aurora\Events;

/**
 * A live page was taken offline.
 */
class PageUnpublished extends PageEvent
{
    public static function name(): string
    {
        return 'page.unpublished';
    }
}
