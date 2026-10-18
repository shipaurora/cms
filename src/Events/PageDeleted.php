<?php

namespace Aurora\Events;

/**
 * A page was moved to the trash.
 */
class PageDeleted extends PageEvent
{
    public static function name(): string
    {
        return 'page.deleted';
    }
}
