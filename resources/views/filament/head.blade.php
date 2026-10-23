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

    {{-- resources/js/admin/canvas-rich-text.js: the canvas's rich text editor, and its wording. --}}
    window.cmsRichText = @js([
        'src' => \Filament\Support\Facades\FilamentAsset::getScriptSrc('rich-text', 'shipaurora/cms'),
        'strings' => [
            'toolbar' => __('Text formatting'),
            'placeholder' => __('Write something…'),
            'locked' => __('This text has a table, image or alignment that can only be changed in the panel on the right.'),
            'link' => __('Link'),
            'linkPrompt' => __('Link to (a page’s address, such as /about, or a full URL). Leave empty to remove the link.'),
            'bold' => __('Bold'),
            'italic' => __('Italic'),
            'underline' => __('Underline'),
            'strike' => __('Strikethrough'),
            'heading2' => __('Heading'),
            'heading3' => __('Subheading'),
            'bulletList' => __('Bulleted list'),
            'orderedList' => __('Numbered list'),
            'blockquote' => __('Quote'),
        ],
    ]);
</script>
