{{-- The dashboard's welcome card: its background is the theme's (.cms-welcome, .cms-welcome-grid). --}}
<x-filament-widgets::widget>
    <div class="cms-welcome relative overflow-hidden rounded-2xl bg-gray-950 p-6 text-white shadow-sm ring-1 ring-gray-950/5 md:p-8 dark:ring-white/10">
        <div class="cms-welcome-grid pointer-events-none absolute inset-0 opacity-30"></div>
        <div class="relative flex flex-col gap-6 2xl:flex-row 2xl:items-end 2xl:justify-between">
            <div>
                <p class="text-sm font-medium text-white/60">{{ str(now()->translatedFormat(__('l, F j')))->ucfirst() }}</p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight md:text-3xl">{{ $greeting }}, {{ str($user->name)->before(' ') }}</h2>
                @php
                    // One sentence per language; the role names go in bold, joined with "and".
                    $roles = $user->roleLabels()->map(fn (string $role): string => '<strong class="text-white">'.e($role).'</strong>')->all();
                    $roleList = \Illuminate\Support\Arr::join($roles, ', ', ' '.e(__('and')).' ');
                    // With more than one site: which one this is.
                    $site = \Aurora\Models\Site::query()->count() > 1 ? \Filament\Facades\Filament::getTenant() : null;
                @endphp
                <p class="mt-2 max-w-xl text-sm text-white/70">
                    @if ($site)
                        {!! strtr(e(__('You are signed in to :site as :roles.')), [':site' => '<strong class="text-white">'.e($site->name).'</strong>', ':roles' => $roleList]) !!}
                    @else
                        {!! strtr(e(__('You are signed in as :roles.')), [':roles' => $roleList]) !!}
                    @endif
                </p>
            </div>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 2xl:w-[46rem]">
                @foreach ($actions as [$label, $description, $icon, $url])
                    <a href="{{ $url }}" @if (str_starts_with($url, \Aurora\Support\CurrentSite::url('/')) && ! str_contains($url, '/admin')) target="_blank" @endif class="group rounded-xl bg-white/5 p-4 ring-1 ring-white/10 backdrop-blur transition hover:bg-white/10">
                        <x-filament::icon :icon="$icon" class="size-6 text-white/80 transition group-hover:scale-110 group-hover:text-white" />
                        <p class="mt-3 text-sm font-semibold">{{ $label }}</p>
                        <p class="mt-0.5 text-xs text-white/60">{{ $description }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
