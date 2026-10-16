<?php

namespace Aurora\Filament\Resources\Pages\Actions;

use Aurora\Enums\PageStatus;
use Aurora\Models\Page;
use Aurora\Support\PageSearch;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;

/**
 * "Search index" for the whole site: try a search and see the pages it finds, most relevant
 * first, with their status and whether visitors can find them. On the pages list, under
 * "More".
 */
class SearchPagesAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'searchPages';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Search index'))
            ->icon('phosphor-magnifying-glass')
            ->visible(fn (): bool => (bool) auth()->user()?->can('manage-search Page'))
            ->modalIcon('phosphor-magnifying-glass')
            ->modalHeading(__('Search index'))
            ->modalDescription(__('Try a search across the site’s pages: the ones it finds, most relevant first, and whether visitors can find them.'))
            ->modalWidth('3xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->schema([
                TextInput::make('query')
                    ->label(__('Try a search'))
                    ->placeholder(__('Words a visitor might type…'))
                    ->prefixIcon('phosphor-magnifying-glass')
                    ->live(debounce: 400),
                View::make('aurora::filament.pages.search-results')
                    ->viewData(fn (Get $get): array => static::results(trim((string) $get('query')))),
            ]);
    }

    /**
     * The pages a search finds (live or not), ranked, with what a visitor would see.
     *
     * @return array<string, mixed>
     */
    public static function results(string $query): array
    {
        if ($query === '') {
            return ['query' => '', 'words' => [], 'pages' => [], 'driver' => (string) config('scout.driver')];
        }

        $pages = Page::searchSite($query, liveOnly: false)->take(50)->get();
        $scores = PageSearch::scores($pages->modelKeys(), $query);

        return [
            'query' => $query,
            'words' => preg_split('/[^\pL\pN]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            'driver' => (string) config('scout.driver'),
            'pages' => $pages->values()->map(fn (Page $page, int $index): array => [
                'page' => $page,
                'rank' => $index + 1,
                'score' => $scores[$page->getKey()] ?? null,
                // Its text by language, for where it matches when the result is opened.
                'texts' => collect(PageSearch::locales($page))->mapWithKeys(fn (string $locale): array => [$locale => PageSearch::textIn($page, $locale)])->filter()->all(),
                // Why visitors don't find it, when they don't.
                'hidden' => match (true) {
                    $page->status === PageStatus::Draft => __('Draft'),
                    $page->status === PageStatus::Review => __('In review'),
                    ! $page->isLive() => __('Scheduled'),
                    (bool) $page->noindex => __('Hidden from search engines'),
                    (bool) $page->exclude_from_search => __('Left out of the search index'),
                    default => null,
                },
            ])->all(),
        ];
    }
}
