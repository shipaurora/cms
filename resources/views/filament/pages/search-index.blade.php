@php
    /**
     * @var array<string, string> $texts  the page's indexed text, by language
     * @var array<int, string> $words  the words searched for, to mark in the text
     * @var array{found: bool, position: ?int, total: int}|null $result
     */
@endphp

<div class="space-y-4">
    @if ($result)
        <div @class(['cms-search-result', 'cms-search-result-found' => $result['found']])>
            <x-filament::icon :icon="$result['found'] ? 'phosphor-check-circle' : 'phosphor-x-circle'" class="size-5 shrink-0" />
            <span>
                @if ($result['found'])
                    {{ trans_choice('Finds this page: result :position of :total.|Finds this page: result :position of :total.', $result['total'], ['position' => $result['position'], 'total' => $result['total']]) }}
                @else
                    {{ trans_choice('Doesn’t find this page (:total other result).|Doesn’t find this page (:total other results).', $result['total'], ['total' => $result['total']]) }}
                @endif
            </span>
        </div>
    @endif

    {{-- Nothing indexed shown until there's a search: then where the page matches. --}}
    @if ($words === [])
        <p class="text-sm text-gray-500">{{ __('Type a search to see which of this page’s indexed text it matches.') }}</p>
    @else
        <div class="cms-search-index-list">
            @include('aurora::filament.pages.search-matches', ['texts' => $texts, 'words' => $words])
        </div>
    @endif

    <p class="text-xs text-gray-500">{{ __('Search driver: :driver. Pages are indexed when they’re saved; “Reindex” does it again.', ['driver' => $driver]) }}</p>
</div>
