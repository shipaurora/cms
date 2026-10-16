@if (config('aurora.demo_login'))
    <div class="mt-6 border-t border-gray-200 pt-6 dark:border-white/10">
        <p class="mb-3 text-center text-xs font-semibold tracking-wide text-gray-500 uppercase">{{ __('Demo: sign in as') }}</p>
        <div class="grid grid-cols-2 gap-2">
            @foreach (\Aurora\Database\Seeders\Demo\UserSeeder::USERS as [$name, $email, $role])
                <a href="{{ route('demo.login', ['email' => $email]) }}" class="flex flex-col rounded-lg border border-gray-200 px-3 py-2 text-left transition hover:border-primary-500 hover:bg-primary-50 dark:border-white/10 dark:hover:bg-white/5">
                    <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $role }}</span>
                    <span class="text-xs text-gray-500">{{ $email }}</span>
                </a>
            @endforeach
        </div>
        <p class="mt-3 text-center text-xs text-gray-400">{{ __('Password for every account:') }} <code>password</code></p>
    </div>
@endif
