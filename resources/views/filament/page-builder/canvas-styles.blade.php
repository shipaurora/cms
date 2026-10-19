{{-- Rendered inside the visual editor's canvas and its preview frame: the website's own
     stylesheet, scoped to the canvas (Aurora\Support\CanvasStyles), its fonts, then the brand
     colours chosen in Site settings, so the canvas matches the live site. --}}
{{ \Aurora\Support\CanvasStyles::fonts() }}
@if ($canvasStyles = \Aurora\Support\CanvasStyles::url())
    <link rel="stylesheet" href="{{ $canvasStyles }}">
@endif
@include('aurora::partials.brand-palette')
