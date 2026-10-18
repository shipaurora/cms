{{-- Settings › Search & SEO › SEO report: the live pages that could do better in search. --}}
@php
    /** @var array<int, array{page: \Aurora\Models\Page, issues: array<int, array{level: string, message: string}>}> $rows */
@endphp

@if ($rows === [])
    <p class="cms-seo-ok">
        <x-filament::icon icon="phosphor-check-circle" class="size-5" />
        {{ __('Every live page is ready for search engines.') }}
    </p>
@else
    <div class="cms-seo-report">
        @foreach ($rows as $row)
            <a href="{{ \Aurora\Filament\Resources\Pages\PageResource::getUrl('edit', ['record' => $row['page']]) }}" class="cms-seo-report-row">
                <span class="cms-seo-report-page">
                    <strong>{{ $row['page']->title }}</strong>
                    <span>{{ \Illuminate\Support\Str::after(\Aurora\Support\CurrentSite::url($row['page']->path()), '://') }}</span>
                </span>
                <ul class="cms-seo-issues">
                    @foreach ($row['issues'] as $issue)
                        <li class="cms-seo-issue cms-seo-issue-{{ $issue['level'] }}">
                            <x-filament::icon :icon="$issue['level'] === 'danger' ? 'phosphor-warning-octagon' : 'phosphor-warning'" class="size-4 shrink-0" />
                            <span>{{ $issue['message'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </a>
        @endforeach
    </div>
@endif
