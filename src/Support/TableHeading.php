<?php

namespace Aurora\Support;

use Illuminate\Support\HtmlString;

/**
 * A table heading with an icon tile, like a section's (aurora.css): for tables that stand in
 * for a section, such as the Languages and Sites tabs in Site settings.
 */
class TableHeading
{
    public static function make(string $icon, string $heading): HtmlString
    {
        return new HtmlString(svg($icon, 'fi-icon cms-ta-heading-icon')->toHtml().e($heading));
    }
}
