@php
    $palette = fn (?string $hex) => is_string($hex) && preg_match('/^#[0-9a-f]{6}$/i', $hex) ? \Filament\Support\Colors\Color::generatePalette($hex) : [];
    $brand = $palette($brand ?? null);
    $accent = $palette($accent ?? null);
@endphp

@if ($brand)
    <div class="space-y-3">
        <div class="flex overflow-hidden rounded-lg ring-1 ring-gray-950/5">
            @foreach ([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950] as $shade)
                <span class="h-6 flex-1" style="background: {{ $brand[$shade] }}" title="brand-{{ $shade }}"></span>
            @endforeach
        </div>
        <div class="relative overflow-hidden rounded-xl p-4 text-white" style="background: radial-gradient(60% 90% at 0% 0%, {{ $brand[500] }}, transparent 70%), radial-gradient(50% 80% at 100% 0%, {{ $accent[400] ?? $brand[300] }}, transparent 70%), #09090b">
            <p class="text-xs font-semibold tracking-widest uppercase opacity-70">{{ __('Preview') }}</p>
            <p class="mt-1 text-lg font-semibold">{{ __('Pages people') }} <span style="background: linear-gradient(100deg, {{ $brand[400] }}, {{ $accent[300] ?? $brand[200] }}); -webkit-background-clip: text; -webkit-text-fill-color: transparent">{{ __('love') }}</span></p>
            <span class="mt-3 inline-block rounded-full px-3 py-1.5 text-xs font-semibold" style="background: {{ $brand[600] }}">{{ __('Get started') }}</span>
        </div>
    </div>
@endif
