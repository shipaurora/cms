{{-- A form submission: when and where it came from, then each answer as a row in one card. --}}
@php
    use Illuminate\Support\Facades\URL;

    /** @var \Aurora\Models\FormSubmission $submission */
    $submission = $getRecord();
    $files = $submission->getMedia('files');
@endphp

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-gray-500 dark:text-gray-400">
        <x-filament::badge :color="$submission->status->getColor()" :icon="$submission->status->getIcon()">
            {{ $submission->status->getLabel() }}
        </x-filament::badge>

        <span class="inline-flex items-center gap-1.5" title="{{ $submission->created_at->isoFormat('LLLL') }}">
            {{ svg('phosphor-calendar-blank', 'size-4') }}
            {{ $submission->created_at->isoFormat('D MMM YYYY, HH:mm') }}
            <span class="text-gray-400 dark:text-gray-500">· {{ $submission->created_at->diffForHumans() }}</span>
        </span>

        @if ($submission->page)
            <a href="{{ $submission->page->url() }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 hover:text-primary-600 dark:hover:text-primary-400">
                {{ svg('phosphor-file-text', 'size-4') }}
                {{ __('Sent from :page', ['page' => $submission->page->title]) }}
            </a>
        @endif
    </div>

    <dl class="divide-y divide-gray-950/5 overflow-hidden rounded-2xl bg-white ring-1 ring-gray-950/5 dark:divide-white/5 dark:bg-white/[0.03] dark:ring-white/10">
        @foreach ($submission->answers() as $answer)
            @php
                $value = $answer['value'];
                $isEmail = filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
                $isUrl = ! $isEmail && \Aurora\Support\SafeUrl::isSafe($value) && preg_match('#^https?://#i', $value);
            @endphp

            <div class="group grid gap-1 px-5 py-3.5 sm:grid-cols-[minmax(0,12rem)_1fr] sm:gap-6" x-data="{ copied: false }">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $answer['label'] }}</dt>
                <dd class="flex min-w-0 items-start justify-between gap-3 text-sm text-gray-950 dark:text-white">
                    @if (blank($value))
                        <span class="text-gray-400 dark:text-gray-500">–</span>
                    @elseif ($isEmail)
                        <a href="mailto:{{ $value }}" class="break-all text-primary-600 hover:underline dark:text-primary-400">{{ $value }}</a>
                    @elseif ($isUrl)
                        <a href="{{ $value }}" target="_blank" rel="noopener nofollow" class="break-all text-primary-600 hover:underline dark:text-primary-400">{{ $value }}</a>
                    @else
                        <span class="min-w-0 break-words whitespace-pre-line">{{ in_array($value, ['Yes', 'No'], true) ? __($value) : $value }}</span>
                    @endif

                    @if (filled($value))
                        <button
                            type="button"
                            class="-my-1 grid size-7 shrink-0 place-items-center rounded-lg text-gray-400 opacity-0 transition group-hover:opacity-100 hover:bg-gray-950/5 hover:text-gray-700 focus-visible:opacity-100 dark:hover:bg-white/10 dark:hover:text-gray-200"
                            x-on:click="navigator.clipboard.writeText(@js($value)); copied = true; setTimeout(() => copied = false, 1500)"
                            :title="copied ? @js(__('Copied')) : @js(__('Copy'))"
                            aria-label="{{ __('Copy') }}"
                        >
                            <span x-show="! copied">{{ svg('phosphor-copy', 'size-4') }}</span>
                            <span x-show="copied" x-cloak class="text-success-600 dark:text-success-400">{{ svg('phosphor-check', 'size-4') }}</span>
                        </button>
                    @endif
                </dd>
            </div>
        @endforeach

        @foreach ($files as $file)
            <div class="grid gap-1 px-5 py-3.5 sm:grid-cols-[minmax(0,12rem)_1fr] sm:gap-6">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $file->getCustomProperty('label', __('File')) }}</dt>
                <dd class="text-sm">
                    <a
                        href="{{ URL::temporarySignedRoute('submission-files.show', now()->addMinutes(30), ['media' => $file->id]) }}"
                        target="_blank"
                        class="inline-flex max-w-full items-center gap-1.5 text-primary-600 hover:underline dark:text-primary-400"
                    >
                        {{ svg('phosphor-paperclip', 'size-4 shrink-0') }}
                        <span class="truncate">{{ $file->file_name }}</span>
                        <span class="shrink-0 text-gray-400 dark:text-gray-500">{{ $file->human_readable_size }}</span>
                    </a>
                </dd>
            </div>
        @endforeach
    </dl>
</div>
