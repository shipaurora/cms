{{-- Overrides the package's public-block to add data-block, so the site's CSS can tell
     full-bleed blocks (bands, heroes) from blocks dropped straight onto the page. --}}
@props(['block', 'blocks' => []])

@php
    use CarlJanzell\FilamentPageBuilder\BlockRegistry;
    use CarlJanzell\FilamentPageBuilder\PageBuilder;
    use CarlJanzell\FilamentPageBuilder\Support\BlockStateNormaliser;
    use CarlJanzell\FilamentPageBuilder\Support\BlockStyle;
    use CarlJanzell\FilamentPageBuilder\Support\BlockTree;

    $registry = app(BlockRegistry::class);
    $definition = $registry->find($block['type'] ?? null);
    $settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];
    $data = app(BlockStateNormaliser::class)->normaliseData(
        $block['data'] ?? [],
        $registry->fileFields($block['type'] ?? null),
    );
    $slotNames = $registry->slots($block['type'] ?? null, $block['data'] ?? []);
    $anchor = BlockTree::isValidAnchor($block['anchor'] ?? null) ? $block['anchor'] : null;
    $style = BlockStyle::compile(is_array($block['style'] ?? null) ? $block['style'] : []);
@endphp

@if ($definition)
    <div
        class="fpb-el{{ $style['class'] ? ' '.$style['class'] : '' }}"
        data-block="{{ $block['type'] }}"
        @if ($anchor) id="{{ $anchor }}" @endif
        @if ($style['combined'] !== '') style="{{ $style['combined'] }}" @endif
        @if ($style['sized']) data-fpb-sized="height" @endif
        @foreach ($settings as $token => $value)
            @if (is_string($token) && preg_match('/^[a-z-]+$/', $token) && is_string($value))
                data-fpb-{{ $token }}="{{ $value }}"
            @endif
        @endforeach
    >
        @include('page-builder::components.background', ['layer' => $style['background']])
        @php
            PageBuilder::rendering($block['type'], $data);

            if ($slotNames !== []) {
                PageBuilder::provideSlots($slotNames, function (string $name) use ($block, $blocks): string {
                    $html = '';

                    foreach (BlockTree::childrenOf($blocks, $block['id'], $name) as $child) {
                        $html .= view('page-builder::components.public-block', [
                            'block' => $child,
                            'blocks' => $blocks,
                        ])->render();
                    }

                    return $html;
                });
            }
        @endphp
        {!! PageBuilder::renderSafely(function () use ($definition, $data): string {
            if (str_contains($definition::view(), '::')) {
                return view($definition::view(), ['data' => $data])->render();
            }

            return view('page-builder::components.dynamic-block', [
                'view' => $definition::view(),
                'data' => $data,
            ])->render();
        }) !!}
    </div>
@endif
