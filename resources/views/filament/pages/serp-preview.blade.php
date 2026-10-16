@php
    $host = parse_url(\Aurora\Support\CurrentSite::url('/'), PHP_URL_HOST);
    $title = \Illuminate\Support\Str::limit(($title ?: 'Untitled page').' · '.\Aurora\Models\Setting::get('site_name', config('app.name')), 62);
    $description = \Illuminate\Support\Str::limit($description ?: 'Add a meta description to control how this page appears in search results.', 158);
@endphp

<div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900">
    <p class="mb-3 flex items-center gap-1.5 text-xs font-medium tracking-wide text-gray-400 uppercase">
        <x-filament::icon icon="phosphor-magnifying-glass-bold" class="size-3.5" /> {{ __('Search result preview') }}
    </p>
    <div class="flex items-center gap-3">
        <span class="grid size-7 place-items-center rounded-full bg-gray-100 text-xs font-bold text-primary-600 dark:bg-white/10">{{ mb_substr(\Aurora\Models\Setting::get('site_name', config('app.name')), 0, 1) }}</span>
        <div class="leading-tight">
            <p class="text-sm text-gray-900 dark:text-white">{{ \Aurora\Models\Setting::get('site_name', config('app.name')) }}</p>
            <p class="text-xs text-gray-500">{{ $host }}{{ $path ? ' › '.str_replace('-', ' ', $path) : '' }}</p>
        </div>
    </div>
    <p class="mt-2 text-xl leading-snug text-[#1a0dab] hover:underline dark:text-[#8ab4f8]">{{ $title }}</p>
    <p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ $description }}</p>
</div>
