<?php

namespace Aurora\Filament;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Support\SearchThumbnail;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;
use Illuminate\Support\Str;

/**
 * The admin's global search: the resources' records, as Filament finds them, and the
 * sections of Settings whose name matches ("layouts", "search index"), straight to them.
 */
class GlobalSearchProvider extends DefaultGlobalSearchProvider
{
    public function getResults(string $query): ?GlobalSearchResults
    {
        $results = parent::getResults($query);
        $query = Str::lower(trim($query));

        $sections = collect(SiteSettings::sectionLinks())
            ->filter(fn (array $section): bool => $query !== '' && (Str::contains(Str::lower($section['label']), $query) || Str::contains(Str::lower(__('Settings')), $query)))
            ->map(fn (array $section): GlobalSearchResult => new GlobalSearchResult(
                title: SearchThumbnail::title($section['label'], null, icon: $section['icon']),
                url: $section['url'],
            ))
            ->values()
            ->all();

        return $sections === [] ? $results : $results?->category(__('Settings'), $sections);
    }
}
