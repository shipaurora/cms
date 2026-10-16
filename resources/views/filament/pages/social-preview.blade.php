@php
    $image = is_array($image ?? null) ? collect($image)->first() : ($image ?? null);
    $url = null;

    if ($image instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
        try { $url = $image->temporaryUrl(); } catch (\Throwable) { $url = null; }
    } elseif (is_string($image) && $image !== '') {
        $url = \Aurora\Support\AssetLibrary::url($image, 'og');
    }
@endphp

<div>
    <p class="mb-2 text-sm font-medium text-gray-950 dark:text-white">{{ __('Preview') }}</p>
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="aspect-[1.91/1] bg-gradient-to-br from-primary-500 via-primary-700 to-gray-900">
            @if ($url)
                <img src="{{ $url }}" alt="" class="size-full object-cover">
            @else
                <div class="flex size-full flex-col justify-end p-5">
                    <p class="text-xs font-semibold tracking-widest text-white/70 uppercase">{{ \Aurora\Models\Setting::get('site_name', config('app.name')) }}</p>
                    <p class="mt-1 line-clamp-2 text-xl font-bold text-white">{{ $title ?: 'Untitled page' }}</p>
                </div>
            @endif
        </div>
        <div class="border-t border-gray-100 p-3 dark:border-white/5">
            <p class="text-xs text-gray-500 uppercase">{{ parse_url(\Aurora\Support\CurrentSite::url('/'), PHP_URL_HOST) }}</p>
            <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $title ?: 'Untitled page' }}</p>
            <p class="line-clamp-1 text-xs text-gray-500">{{ $description }}</p>
        </div>
    </div>
</div>
