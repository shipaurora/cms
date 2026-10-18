{{-- Settings › System › System info: what the CMS runs on (Aurora\Support\SystemInfo). --}}
@php
    /** @var array<int, array{label: string, value: string, warning: ?string}> $rows */
@endphp

<dl class="cms-system-info">
    @foreach ($rows as $row)
        <div @class(['cms-system-info-warning' => $row['warning']])>
            <dt>{{ $row['label'] }}</dt>
            <dd>
                <code>{{ $row['value'] }}</code>
                @if ($row['warning'])
                    <span><x-filament::icon icon="phosphor-warning" class="size-4" /> {{ $row['warning'] }}</span>
                @endif
            </dd>
        </div>
    @endforeach
</dl>
