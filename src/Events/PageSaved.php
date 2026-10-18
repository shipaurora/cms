<?php

namespace Aurora\Events;

/**
 * A page was saved (from the admin, the canvas or an AI app).
 */
class PageSaved extends PageEvent
{
    public static function name(): string
    {
        return 'page.saved';
    }
}
