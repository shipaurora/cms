@props(['data' => []])

@php
    use Workbench\App\Blocks\BandBlock;
    use Aurora\Support\AssetLibrary;

    $background = array_key_exists($data['background'] ?? '', BandBlock::BACKGROUNDS) ? $data['background'] : 'white';
    $dark = in_array($background, ['brand', 'dark', 'aurora', 'image'], true);
    $image = $background === 'image' ? AssetLibrary::url($data['image'] ?? null, 'lg') : null;
    $overlay = max(0, min(90, (int) ($data['overlay'] ?? 50))) / 100;

    $padding = match ($data['padding'] ?? 'lg') {
        'none' => 'py-0',
        'sm' => 'py-8 @3xl:py-12',
        'md' => 'py-12 @3xl:py-16',
        'xl' => 'py-24 @3xl:py-36',
        default => 'py-16 @3xl:py-24',
    };

    $width = match ($data['width'] ?? 'default') {
        'narrow' => 'max-w-3xl px-6',
        'wide' => 'max-w-7xl px-6 @3xl:px-8',
        'full' => 'max-w-none',
        default => 'max-w-6xl px-6 @3xl:px-8',
    };
@endphp

<section @class([
    'relative isolate overflow-hidden @container',
    'tone-dark' => $dark,
    'tone-light' => ! $dark,
    'bg-white' => $background === 'white',
    'bg-zinc-50' => $background === 'muted',
    'bg-brand-50/70' => $background === 'brand-soft',
    'bg-brand-600' => $background === 'brand',
    'bg-zinc-950' => $background === 'dark' || ($background === 'image' && ! $image),
    'bg-aurora' => $background === 'aurora',
])>
    @if ($image)
        <x-aurora::media :value="$data['image']" size="lg" alt="" class="absolute inset-0 -z-10 size-full object-cover" />
        <div class="absolute inset-0 -z-10 bg-zinc-950" style="opacity: {{ $overlay }}"></div>
    @endif

    @if ($background === 'brand')
        <div class="pointer-events-none absolute -top-40 -right-32 -z-10 size-[30rem] rounded-full bg-brand-400/40 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-48 -left-24 -z-10 size-[26rem] rounded-full bg-accent-400/25 blur-3xl"></div>
    @endif

    @if ($background === 'aurora')
        <div class="pointer-events-none absolute inset-0 -z-10 bg-grid opacity-60"></div>
    @endif

    <div class="relative mx-auto {{ $width }} {{ $padding }}">
        {{ block_slot('content') }}
    </div>
</section>
