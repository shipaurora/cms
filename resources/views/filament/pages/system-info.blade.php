{{-- Settings › System › System info: what the CMS runs on (Aurora\Support\SystemInfo), with
     whether a newer Aurora is out. --}}
@php
    /** @var array<int, array{label: string, value: string, warning: ?string, badge?: array{label: string, color: string}, url?: ?string}> $rows */
@endphp

<dl class="cms-system-info">
    @foreach ($rows as $row)
        <div @class(['cms-system-info-warning' => $row['warning']])>
            <dt>{{ $row['label'] }}</dt>
            <dd>
                <code>{{ $row['value'] }}</code>
                {{-- Styled here, not in components.css: that's built into each theme, and its rule
                     for this row's warning line would restyle a badge's spans. --}}
                @isset ($row['badge'])
                    <b style="display: inline-block; margin-inline-start: 0.5rem; padding: 0.125rem 0.5rem; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 600; line-height: 1rem; color: var(--color-{{ $row['badge']['color'] }}-600); background: color-mix(in oklch, var(--color-{{ $row['badge']['color'] }}-500) 15%, transparent)">{{ $row['badge']['label'] }}</b>
                @endisset
                @if ($row['warning'])
                    <span><x-filament::icon icon="phosphor-warning" class="size-4" /> {{ $row['warning'] }}</span>
                @endif
                @if (filled($row['url'] ?? null))
                    <a href="{{ $row['url'] }}" target="_blank" rel="noopener" style="display: inline-block; margin-top: 0.25rem; font-weight: 500; color: var(--color-primary-600)">{{ __('See what changed') }} →</a>
                @endif
            </dd>
        </div>
    @endforeach
</dl>
