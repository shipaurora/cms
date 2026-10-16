@php
    /**
     * @var string $query
     * @var string $driver
     * @var array<int, string> $words  the words searched for
     * @var array<int, array{page: \Aurora\Models\Page, rank: int, score: ?float, hidden: ?string, texts: array<string, string>}> $pages
     */
@endphp

<div class="space-y-3">
    @if ($query === '')
        <p class="text-sm text-gray-500">{{ __('Type a search to see which pages it finds.') }}</p>
    @elseif ($pages === [])
        <p class="text-sm text-gray-500">{{ __('No pages match “:query”.', ['query' => $query]) }}</p>
    @else
        <ol class="cms-search-pages">
            @foreach ($pages as ['page' => $page, 'rank' => $rank, 'score' => $score, 'hidden' => $hidden, 'texts' => $texts])
                {{-- A click opens it: where it matches, as the page's own search tester shows. --}}
                <li x-data="{ open: false }" x-bind:class="open && 'cms-search-pages-open'" wire:key="search-result-{{ $page->getKey() }}">
                    <div class="cms-search-pages-row" role="button" tabindex="0" x-on:click="open = ! open" x-on:keydown.enter.prevent="open = ! open" x-on:keydown.space.prevent="open = ! open" x-bind:aria-expanded="open">
                        <span class="cms-search-pages-rank">{{ $rank }}</span>
                        <span class="cms-search-pages-page">
                            <span class="cms-search-pages-title">{{ $page->title }}</span>
                            <span class="cms-search-pages-path">{{ $page->path() }}</span>
                        </span>
                        <x-filament::badge :color="$page->status->getColor()" :icon="$page->status->getIcon()" size="sm">{{ $page->status->getLabel() }}</x-filament::badge>
                        @if ($hidden)
                            <span class="cms-search-pages-hidden" title="{{ __('Visitors’ searches don’t find it') }}">{{ __('Not in search: :reason', ['reason' => mb_strtolower($hidden)]) }}</span>
                        @endif
                        @if ($score !== null)
                            <span class="cms-search-pages-score" title="{{ __('How well it matches (MySQL full-text relevance)') }}">{{ \Illuminate\Support\Number::format($score, maxPrecision: 2) }}</span>
                        @endif
                        <x-filament::icon-button
                            tag="a"
                            :href="\Aurora\Filament\Resources\Pages\PageResource::getUrl('edit', ['record' => $page])"
                            icon="phosphor-arrow-square-up-right"
                            color="gray"
                            size="sm"
                            :label="__('Open')"
                            :tooltip="__('Open')"
                            x-on:click.stop
                        />
                        <span class="cms-search-pages-caret" x-bind:class="open && 'rotate-180'">{{ svg('phosphor-caret-down-bold', 'size-3.5') }}</span>
                    </div>
                    <div x-show="open" x-collapse x-cloak class="cms-search-pages-matches">
                        @include('aurora::filament.pages.search-matches', ['texts' => $texts, 'words' => $words])
                    </div>
                </li>
            @endforeach
        </ol>
        <p class="text-xs text-gray-500">{{ trans_choice(':count page, most relevant first. Search driver: :driver.|:count pages, most relevant first. Search driver: :driver.', count($pages), ['driver' => $driver]) }}</p>
    @endif
</div>
