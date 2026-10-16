{{-- The CMS mark (packages/cms/resources/images/brand-mark.svg, in the theme's violet) and the app name in the
     theme's display face. An <img>, so its SVG gradient never clashes between the copies
     of the logo on a page. --}}
<div class="flex items-center gap-2.5">
    <img src="{{ route('aurora.brand', 'brand-mark.svg') }}" alt="" class="size-7 shrink-0 rounded-[0.55rem] shadow-[0_6px_16px_-6px_rgb(124_58_237/0.6)]" />
    <span class="cms-wordmark text-gray-950 dark:text-white">{{ config('aurora.name') }}</span>
</div>
