{{-- A JSON value as a readable, copyable code block (used for MCP tool arguments). --}}
@php
    $state = $getState();
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if (blank($state))
        <p class="text-sm text-gray-400 dark:text-gray-500">{{ __('No arguments: the assistant called this tool without input.') }}</p>
    @else
        @include('aurora::filament.pages.partials.snippet', ['code' => $json])
    @endif
</x-dynamic-component>
