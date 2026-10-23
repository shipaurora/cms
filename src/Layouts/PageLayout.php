<?php

namespace Aurora\Layouts;

use Filament\Schemas\Components\Component;

/**
 * A layout defined in code, a site's or a component library's, registered from a service
 * provider like blocks are:
 *
 *     Layouts::register([CampaignLayout::class]);
 *
 * It's offered with the layouts added in the admin and can be made the default, but its
 * name, view and fields are the code's: it can't be edited or deleted there.
 */
abstract class PageLayout
{
    /**
     * What pages and code know it by ("campaign"): lowercase letters, numbers and dashes.
     * Changing it makes it a new layout.
     */
    abstract public static function handle(): string;

    abstract public static function name(): string;

    /**
     * The Blade view that draws the page; it gets $page, $layout, $settings, $preview and $embedded.
     */
    abstract public static function view(): string;

    /**
     * Shown with it when choosing a page's layout.
     */
    public static function description(): ?string
    {
        return null;
    }

    /**
     * A Phosphor icon, like phosphor-megaphone.
     */
    public static function icon(): ?string
    {
        return null;
    }

    /**
     * Fields of its own, for what doesn't fit in a block (a background image): shown under
     * the layout picker, and given to the view as $settings (Layouts::schema()).
     *
     * @return array<int, Component>
     */
    public static function schema(): array
    {
        return [];
    }
}
