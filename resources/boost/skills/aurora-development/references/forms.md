# A form block

Editors build forms under Content › Forms (`Aurora\Models\Form`): the fields, the thank-you message and who hears about submissions. A website shows one with a block of its own: a `Select` of forms in the block's schema, and a view that draws the fields and posts them to Aurora. Aurora validates, stores the submission, sends the notifications and redirects back. The view only has to keep to the contract below.

## The block

```php
use Aurora\Models\Form;

public static function schema(): array
{
    return [
        Select::make('form_id')->label(__('Form'))
            ->options(fn (): array => Form::query()->orderBy('name')->pluck('name', 'id')->all())
            ->required(),
    ];
}
```

## The view

```blade
@props(['data' => []])

@php
    $form = \Aurora\Models\Form::query()->find($data['form_id'] ?? null);
    $bag = $form ? 'form_'.$form->id : null;
    $errors = $bag ? (session('errors')?->getBag($bag) ?? new \Illuminate\Support\MessageBag) : new \Illuminate\Support\MessageBag;
    $sent = $bag && session($bag.'.sent');
    $fields = $form?->definitions() ?? [];
@endphp

@if (! $form)
    @editing <p>{{ __('Choose a form in the panel →') }}</p> @endediting
@else
    <div id="form-{{ $form->id }}">
        @if ($sent)
            <p>{{ $form->success_message ?: __('Your message has been sent.') }}</p>
        @else
            <form method="POST" action="{{ is_editing() ? '#' : route('forms.submit', $form) }}"
                  @if (collect($fields)->contains('type', 'file')) enctype="multipart/form-data" @endif
                  @editing onsubmit="return false" @endediting>
                @csrf
                <input type="hidden" name="_started" value="{{ Crypt::encryptString((string) now()->timestamp) }}">
                <input type="hidden" name="_page" value="{{ current_page()?->id }}">
                <input type="hidden" name="_locale" value="{{ \Aurora\Support\ContentLocale::current() }}">
                <div hidden aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>

                @foreach ($fields as $field)
                    {{-- One input per field, named $field['name'] (see below). --}}
                    @if ($errors->has($field['name'])) <p>{{ $errors->first($field['name']) }}</p> @endif
                @endforeach

                <button type="submit">{{ __('Send') }}</button>
            </form>
        @endif
    </div>
@endif
```

## The contract

- **Address:** `POST route('forms.submit', $form)`. Never while editing: the canvas must not send forms.
- **Hidden fields:** `@csrf`; `_started`, the time the form was drawn, encrypted (`Crypt::encryptString((string) now()->timestamp)`); `_page`, the page it's on (`current_page()?->id`); `_locale`, the language it's in (`ContentLocale::current()`), so the thank-you text and notifications are in the visitor's language.
- **Spam:** a text input named `website`, hidden from people (the honeypot). A submission with it filled in, or sent less than two seconds after the form was drawn, is thrown away without a word, and the visitor sees the thank-you as usual. Keep both.
- **Back on the page:** Aurora redirects to the page it came from, at `#form-{id}`, so the element around the form needs `id="form-{{ $form->id }}"`. Errors are in the error bag `form_{id}` (not the default bag, so two forms on a page don't share errors) and the old input in `old($field['name'])`; after a submission, `session('form_{id}.sent')` is true.
- **Fields:** `$form->definitions()` gives them flat, in the language being shown: each `['type', 'name', 'label', 'required', 'data']`. Name each input `$field['name']`; `checkboxes` send `name[]`. Types (`Aurora\Support\FormFields::TYPES`): `text`, `email`, `phone`, `date`, `textarea`, `select`, `radio` and `checkboxes` (the last three with their choices in `$field['data']['options']`), `consent` (one checkbox, its text in `$field['data']['text']`) and `file` (needs `enctype="multipart/form-data"` on the form). `$field['data']['placeholder']` and `['help']` are optional, and `['width']` is `full` or `half` (two half fields side by side).
- **Thank-you:** `$form->success_message`, else a text of the site's own.

The docs site's `resources/views/components/blocks/form.blade.php` is a complete one, styled, with every field type.
