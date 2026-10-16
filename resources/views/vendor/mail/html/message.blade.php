<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('aurora.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ __('Sent by :name', ['name' => config('aurora.name')]) }} · [{{ parse_url(config('app.url'), PHP_URL_HOST) }}]({{ config('app.url') }})
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
