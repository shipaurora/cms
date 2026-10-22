@props(['data' => []])

@php
    use Aurora\Support\AssetLibrary;

    $url = AssetLibrary::url($data['src'] ?? null, 'lg');
    $ratio = in_array($data['ratio'] ?? 'auto', ['16/9', '4/3', '1/1', '3/4'], true) ? $data['ratio'] : null;
@endphp

<figure>
    @if ($url)
        <x-aurora::media
            :value="$data['src'] ?? null"
            size="lg"
            sizes="(min-width: 1024px) 66vw, 100vw"
            :alt="filled($data['alt'] ?? null) ? $data['alt'] : null"
            :style="$ratio ? 'aspect-ratio: '.$ratio : null"
            @class([
                'w-full object-cover',
                'rounded-3xl' => $data['rounded'] ?? true,
                'shadow-2xl shadow-zinc-900/20' => $data['shadow'] ?? false,
            ])
        />
    @else
        <div class="grid aspect-video w-full place-items-center rounded-3xl border-2 border-dashed border-line text-sm text-ink-soft transition hover:border-brand-400 hover:text-brand-600" {!! \Aurora\Blocks\InlineEditing::pickerAttributes('src') !!}>
            <span class="flex flex-col items-center gap-2">
                {{ svg('hero-o-photo', 'size-10 opacity-50') }}
                @if (is_editing())<span class="text-xs font-medium">{{ __('Choose an image') }}</span>@endif
            </span>
        </div>
    @endif

    @if (shows_field('caption', $data['caption'] ?? null))
        <figcaption class="mt-3 text-center text-sm text-ink-soft" @editable('caption')>{{ plain_text($data['caption'] ?? '') }}</figcaption>
    @endif
</figure>
