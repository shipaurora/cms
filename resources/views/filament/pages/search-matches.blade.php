@php
    /**
     * Where a page matches a search: in each of its languages, the lines with a word searched
     * for, the words marked (as the search finds them: by their start). Used by the page's
     * search tester and by each result of the site's.
     *
     * @var array<string, string> $texts  the page's indexed text, by language
     * @var array<int, string> $words  the words searched for
     */
    $locales = \Aurora\Support\ContentLocale::all();
    $pattern = '/(?<![\pL\pN])('.implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), $words)).')/iu';
    $lines = fn (string $text): array => array_values(array_filter(explode("\n", $text), fn (string $line): bool => (bool) preg_match($pattern, $line)));
    $mark = function (string $text) use ($words): string {
        $html = e($text);

        foreach ($words as $word) {
            $html = preg_replace('/(?<![\pL\pN])('.preg_quote(e($word), '/').'[\pL\pN]*)/iu', '<mark class="cms-search-mark">$1</mark>', $html);
        }

        return $html;
    };
@endphp

@forelse ($texts as $locale => $text)
    @php($matching = $lines($text))
    <section class="cms-search-index">
        <header>
            <span>{{ $locales[$locale] ?? $locale }}</span>
            <span class="cms-search-index-meta">{{ trans_choice(':count matching line|:count matching lines', count($matching)) }}</span>
        </header>
        @if ($matching === [])
            <p class="text-gray-500">{{ __('Nothing in this language matches.') }}</p>
        @else
            <p>{!! implode('<br>', array_map($mark, $matching)) !!}</p>
        @endif
    </section>
@empty
    <p class="text-sm text-gray-500">{{ __('Nothing to index yet: the page has no text.') }}</p>
@endforelse
