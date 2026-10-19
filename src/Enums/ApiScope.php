<?php

namespace Aurora\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * What an API key may do (Aurora\Models\ApiKey): each endpoint of the REST API, and each
 * query of the GraphQL API, needs one of these.
 */
enum ApiScope: string implements HasDescription, HasLabel
{
    case PagesList = 'pages.list';
    case PagesRead = 'pages.read';
    case Search = 'search';
    case MediaRead = 'media.read';
    case MenusRead = 'menus.read';
    case SettingsRead = 'settings.read';

    public function getLabel(): string
    {
        return match ($this) {
            self::PagesList => __('List pages'),
            self::PagesRead => __('Read page content'),
            self::Search => __('Search'),
            self::MediaRead => __('Read files'),
            self::MenusRead => __('Read menus'),
            self::SettingsRead => __('Read settings'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::PagesList => __('The live pages, in brief: titles and addresses.'),
            self::PagesRead => __('A page with everything on it: its blocks and SEO.'),
            self::Search => __('Search the website’s pages.'),
            self::MediaRead => __('The files the website’s pages use.'),
            self::MenusRead => __('The website’s menus.'),
            self::SettingsRead => __('The website’s public settings: its name, colours and the like.'),
        };
    }

    /**
     * Every scope, for a key that may do it all.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }
}
