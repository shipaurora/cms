@props(['data' => []])

@php
    use Workbench\App\Blocks\CodeSampleBlock;

    $language = $data['language'] ?? 'php';
    $label = CodeSampleBlock::LANGUAGES[$language][0] ?? strtoupper($language);
    $filename = $data['filename'] ?? null;
    $code = rtrim((string) ($data['code'] ?? ''));
    // Terminal commands get a prompt, which isn't copied.
    $lines = $language === 'bash'
        ? collect(explode("\n", $code))->map(fn (string $line): string => '<span class="select-none text-brand-400">$ </span>'.e($line))->implode("\n")
        : e($code);
@endphp

{{-- Code to read and copy: always on a dark panel, whatever the band behind it. --}}
<figure class="tone-dark not-prose overflow-hidden rounded-2xl bg-zinc-950 shadow-lg ring-1 ring-white/10" x-data="{ copied: false }">
    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-2.5">
        <span class="flex gap-1.5" aria-hidden="true">
            <span class="size-2.5 rounded-full bg-white/15"></span><span class="size-2.5 rounded-full bg-white/15"></span><span class="size-2.5 rounded-full bg-white/15"></span>
        </span>
        <span class="min-w-0 flex-1 truncate font-mono text-xs text-zinc-300">{{ filled($filename) ? $filename : ($language === 'bash' ? '~' : '') }}</span>
        <span class="rounded-md bg-white/10 px-1.5 py-0.5 text-[0.65rem] font-semibold tracking-wide text-zinc-300 uppercase">{{ $label }}</span>
        <button
            type="button"
            class="rounded-md px-2 py-1 text-xs font-medium text-zinc-300 transition hover:bg-white/10 hover:text-white"
            x-on:click="navigator.clipboard.writeText({{ Js::from($code) }}).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
        >
            <span x-show="! copied">{{ __('Copy') }}</span>
            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
        </button>
    </div>
    <pre class="overflow-x-auto p-4 text-[0.8125rem]/6 text-zinc-100"><code class="font-mono">{!! $lines !!}</code></pre>
    @if (shows_field('caption', $data['caption'] ?? null))
        <figcaption class="border-t border-white/10 px-4 py-2.5 text-xs text-zinc-400" @editable('caption')>{{ plain_text($data['caption'] ?? '') }}</figcaption>
    @endif
</figure>
