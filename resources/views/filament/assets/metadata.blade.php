@php
    /**
     * A file's embedded data (EXIF in photos, tags in audio): $reader is the class that reads
     * it (ImageMetadata, AudioMetadata). Only shown when the file has some.
     *
     * @var \Aurora\Models\Asset $asset
     */
    $asset = $getRecord();
    $metadata = $reader::read($asset);
@endphp

{{-- The useful parts first, every tag below them, folded away. --}}
<div class="space-y-4">
    @if ($metadata['summary'] !== [])
        <dl class="cms-exif-summary">
            @foreach ($metadata['summary'] as $label => $item)
                <div>
                    <dt>{{ $label }}</dt>
                    <dd>
                        @if (isset($item['url']))
                            <a href="{{ $item['url'] }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">{{ $item['value'] }}</a>
                        @else
                            {{ $item['value'] }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif

    <details class="cms-exif-all">
        <summary>{{ trans_choice('All data (:count tag)|All data (:count tags)', count($metadata['all'])) }}</summary>
        <dl>
            @foreach ($metadata['all'] as $tag => $value)
                <div>
                    <dt>{{ $tag }}</dt>
                    <dd>{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </details>
</div>
