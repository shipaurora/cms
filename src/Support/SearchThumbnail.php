<?php

namespace Aurora\Support;

use BackedEnum;
use Illuminate\Support\HtmlString;

use function Filament\Support\generate_icon_html;

/**
 * A global search result title with a small picture in front: an image's thumbnail,
 * a person's avatar, or, for things without a picture, the icon they have in the
 * sidebar. The picture is placed beside the title and details by the admin theme
 * (.cms-search-thumb).
 */
class SearchThumbnail
{
    public static function title(string $title, ?string $imageUrl, bool $round = false, string|BackedEnum|null $icon = null): HtmlString|string
    {
        if (filled($imageUrl)) {
            return new HtmlString(sprintf(
                '<span class="cms-search-thumb%s"><img src="%s" alt="" loading="lazy"></span>%s',
                $round ? ' cms-search-thumb-round' : '',
                e($imageUrl),
                e($title),
            ));
        }

        if ($icon === null) {
            return $title;
        }

        return new HtmlString(sprintf(
            '<span class="cms-search-thumb cms-search-thumb-icon">%s</span>%s',
            generate_icon_html($icon)?->toHtml(),
            e($title),
        ));
    }
}
