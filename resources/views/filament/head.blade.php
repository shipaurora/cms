{{--
    The admin's extra fonts (Inter comes from the panel's ->font()), its scripts, and what
    they need from the server (AdminPanelProvider, HEAD_END).
--}}
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=bricolage-grotesque:500,600,700,800|space-grotesk:500,600,700|jetbrains-mono:400,500" rel="stylesheet">

{{-- Aurora's prebuilt admin scripts (resources/dist/aurora.js, published by filament:assets). --}}
<script type="module" src="{{ \Filament\Support\Facades\FilamentAsset::getScriptSrc('aurora', 'shipaurora/cms') }}"></script>

<script>
    {{-- resources/js/admin/copilot.js: the tools that change content, the screens that show it, and its wording. --}}
    window.cmsCopilot = @js([
        'changingTools' => \Aurora\Support\CopilotTools::CHANGING,
        'panelPath' => '/'.trim(filament()->getPanel('admin')->getPath(), '/'),
        'strings' => [
            'changed' => __('The assistant changed this content'),
            'unsaved' => __('You have unsaved changes here, so it wasn’t reloaded. Save or discard them, then reload to see the new version.'),
            'reload' => __('Reload'),
        ],
    ]);
</script>
