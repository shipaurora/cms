{{--
    Overrides the package's embed block view. Identical on the public site; on the canvas
    the embed is also a drop zone: drag a link (from the address bar, another tab or a
    document) onto it to set or replace its source (DesignPage::setEmbedUrl()).
--}}
@props(['data' => []])

@php
    use CarlJanzell\FilamentPageBuilder\Blocks\EmbedBlock;
    use CarlJanzell\FilamentPageBuilder\PageBuilder;
    use CarlJanzell\FilamentPageBuilder\Support\EmbedUrl;
    use Illuminate\Support\Js;

    $editing = PageBuilder::isEditing();
    $src = EmbedBlock::src($data, $editing);
    $ratio = array_key_exists($data['ratio'] ?? '', EmbedBlock::RATIOS) ? $data['ratio'] : '16-9';
    $height = is_numeric($data['height'] ?? null) ? max(80, min(2000, (int) $data['height'])) : null;
    $title = filled($data['title'] ?? null) ? $data['title'] : __('page-builder::blocks.embed.title_placeholder');
@endphp

@if ($editing)
    <div
        data-fpb-dropzone
        x-data="{
            over: false,
            isLink(event) { return [...(event.dataTransfer?.types ?? [])].includes('text/uri-list') },
            linkFrom(event) {
                const list = event.dataTransfer.getData('text/uri-list').split(/\r?\n/).find((line) => line && ! line.startsWith('#'))
                return (list || event.dataTransfer.getData('text/plain') || '').trim()
            },
        }"
        x-on:dragenter="if (isLink($event)) { $event.preventDefault(); $event.stopPropagation(); over = true }"
        x-on:dragover="if (isLink($event)) { $event.preventDefault(); $event.stopPropagation(); $event.dataTransfer.dropEffect = 'link'; over = true }"
        x-on:dragleave="if (! $el.contains($event.relatedTarget)) over = false"
        x-on:drop="if (isLink($event)) { $event.preventDefault(); $event.stopPropagation(); over = false; const url = linkFrom($event); if (url) $wire.setEmbedUrl({{ Js::from(PageBuilder::currentBlockId()) }}, url) }"
        class="relative"
    >
@endif

@if ($src)
    <div class="fpb-embed" data-fpb-ratio="{{ $ratio }}" @if ($height) style="height: {{ $height }}px; padding-bottom: 0" @endif>
        <iframe
            src="{{ $src }}"
            title="{{ $title }}"
            loading="lazy"
            referrerpolicy="strict-origin-when-cross-origin"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen"
            allowfullscreen
        ></iframe>
    </div>
    @if ($editing && ($data['autoplay'] ?? false))
        <p class="fpb-embed-note">{{ __('page-builder::blocks.embed.canvas_autoplay_note') }}</p>
    @endif
@elseif ($editing && filled($data['url'] ?? null))
    <div class="fpb-embed-placeholder">{{ EmbedUrl::problem($data['url']) }}</div>
@elseif ($editing)
    <div class="fpb-embed-placeholder fpb-embed-dropzone">
        <strong>{{ __('Drop a link here') }}</strong> {{ __('— YouTube, Vimeo, Google Maps, Forms, Slides …') }}<br>
        {{ __('or paste a link or embed code in the sidebar.') }}
    </div>
@endif

@if ($editing)
        {{-- Shown while a link is dragged over the embed, also over a playing iframe. --}}
        <div x-show="over" x-cloak class="fpb-embed-drop-overlay">
            {{ $src ? __('Drop to replace this embed') : __('Drop to embed this link') }}
        </div>
    </div>
@endif
