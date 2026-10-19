<?php

namespace Aurora\Filament\Resources\Pages\Actions;

use Aurora\Models\Page;
use Aurora\Support\PageSearch;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;

/**
 * "Search index": what search knows about a page (its text, language by language) and a
 * box to try searches with, showing whether they find this page and where it comes among
 * the site's results. On the page's edit page, under "More".
 */
class SearchIndexAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'searchIndex';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Search index'))
            ->icon('phosphor-magnifying-glass')
            ->visible(fn (): bool => (bool) auth()->user()?->can('manage-search Page'))
            ->modalIcon('phosphor-magnifying-glass')
            ->modalHeading(fn (Page $record): string => __('Search index for “:page”', ['page' => $record->title]))
            ->modalDescription(fn (): string => __('What search knows about this page, from its titles, excerpt, meta description and blocks. Try a search to see whether it finds the page.'))
            ->modalWidth('3xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->schema(fn (Page $record): array => [
                // Not in search at all: why, before anyone wonders why their test search finds nothing.
                Callout::make(__('This page isn’t in search'))
                    ->description(fn (): string => match (true) {
                        $record->trashed() => __('It’s in the trash.'),
                        (bool) $record->exclude_from_search => __('It’s left out of the search index (Details).'),
                        (bool) $record->noindex => __('It’s hidden from search engines (SEO settings), so it’s left out of the site’s search too.'),
                        default => __('Only live pages are searchable: publish it to add it.'),
                    })
                    ->icon('phosphor-eye-slash')
                    ->warning()
                    ->visible(! $record->shouldBeSearchable()),
                TextInput::make('query')
                    ->label(__('Try a search'))
                    ->placeholder(__('Words a visitor might type…'))
                    ->prefixIcon('phosphor-magnifying-glass')
                    ->live(debounce: 400),
                View::make('aurora::filament.pages.search-index')
                    ->viewData(fn (Get $get): array => static::details($record, trim((string) $get('query')))),
            ]);
    }

    /**
     * The page's indexed text by language, and how a search does: found or not, and where.
     *
     * @return array<string, mixed>
     */
    public static function details(Page $page, string $query): array
    {
        $result = null;

        if ($query !== '') {
            $found = Page::search($query)->take(100)->get()->modelKeys();
            $position = array_search($page->getKey(), $found, true);
            $result = ['found' => $position !== false, 'position' => $position === false ? null : $position + 1, 'total' => count($found)];
        }

        return [
            'driver' => (string) config('scout.driver'),
            'texts' => collect(PageSearch::locales($page))->mapWithKeys(fn (string $locale): array => [$locale => PageSearch::textIn($page, $locale)])->filter()->all(),
            'words' => preg_split('/[^\pL\pN]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            'result' => $result,
        ];
    }
}
