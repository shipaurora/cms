{{-- What would help a page in search results (Aurora\Support\SeoChecks), on its Search engines tab. --}}
@php
    /** @var array<int, array{level: string, message: string}> $issues */
@endphp

@if ($issues === [])
    <p class="cms-seo-ok">
        <x-filament::icon icon="phosphor-check-circle" class="size-5" />
        {{ __('Nothing to fix: the page is ready for search engines.') }}
    </p>
@else
    <ul class="cms-seo-issues">
        @foreach ($issues as $issue)
            <li class="cms-seo-issue cms-seo-issue-{{ $issue['level'] }}">
                <x-filament::icon :icon="match ($issue['level']) { 'danger' => 'phosphor-warning-octagon', 'warning' => 'phosphor-warning', default => 'phosphor-info' }" class="size-5 shrink-0" />
                <span>{{ $issue['message'] }}</span>
            </li>
        @endforeach
    </ul>
@endif
