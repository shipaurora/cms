@props(['data' => []])

@php
    use Aurora\Models\Form;
    use Aurora\Support\FormFields;
    use Illuminate\Support\Facades\Crypt;

    $currentPage ??= null;
    $form = Form::query()->find($data['form_id'] ?? null);
    $editing = is_editing();
    $bag = $form ? 'form_'.$form->id : null;
    $errors = $bag ? (session('errors')?->getBag($bag) ?? new \Illuminate\Support\MessageBag) : new \Illuminate\Support\MessageBag;
    $sent = $bag && session($bag.'.sent');
    $fields = $form?->definitions() ?? [];
    $hasFiles = FormFields::uploads($fields);

    $input = 'block w-full rounded-xl border-0 bg-white px-4 py-3 text-zinc-900 shadow-sm ring-1 ring-zinc-300 ring-inset placeholder:text-zinc-400 focus:ring-2 focus:ring-brand-500 focus:ring-inset';
@endphp

@if (! $form)
    @if ($editing)
        <div class="rounded-2xl border-2 border-dashed border-line p-8 text-center text-sm text-ink-soft">{{ __('Choose a form in the panel →') }}</div>
    @endif
@else
    <div id="form-{{ $form->id }}" @class([
        'tone-light scroll-mt-28',
        'rounded-3xl bg-white p-6 shadow-xl shadow-zinc-900/5 ring-1 ring-zinc-900/5 sm:p-8' => ($data['style'] ?? 'card') === 'card',
    ])>
        @if ($sent)
            <div class="flex flex-col items-center gap-3 py-10 text-center">
                <span class="grid size-12 place-items-center rounded-full bg-emerald-50 text-emerald-600 ring-1 ring-emerald-200">{{ svg('hero-o-check', 'size-6') }}</span>
                <p class="font-display text-xl font-semibold text-zinc-950">{{ __('Thank you!') }}</p>
                <p class="max-w-sm text-zinc-600">{{ $form->success_message ?: __('Your message has been sent.') }}</p>
            </div>
        @else
            <form
                method="POST"
                action="{{ $editing ? '#' : route('forms.submit', $form) }}"
                @if ($hasFiles) enctype="multipart/form-data" @endif
                class="grid grid-cols-1 gap-x-4 gap-y-5 sm:grid-cols-2"
                @if ($editing) onsubmit="return false" @endif
                {{-- Fields shown only for an answer (FormFields::visible() checks the same). --}}
                x-data="{
                    answers: {},
                    read() { const data = new FormData($el); this.answers = {}; for (const [key, value] of data.entries()) { const name = key.replace(/\[\]$/, ''); (this.answers[name] ??= []).push(value === '1' ? 'Yes' : value) } },
                    shows(field, value) { return (this.answers[field] ?? []).includes(value) },
                }"
                x-init="read()"
                x-on:input="read()"
                x-on:change="read()"
            >
                @csrf
                <input type="hidden" name="_started" value="{{ Crypt::encryptString((string) now()->timestamp) }}">
                <input type="hidden" name="_page" value="{{ $currentPage?->id }}">
                <input type="hidden" name="_locale" value="{{ \Aurora\Support\ContentLocale::current() }}">
                {{-- Honeypot: invisible to people, irresistible to bots. --}}
                <div class="absolute -left-[9999px]" aria-hidden="true">
                    <label>{{ __('Website') }} <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>

                @foreach ($fields as $field)
                    @php
                        $name = $field['name'];
                        $id = 'f-'.substr($form->id, 0, 8).'-'.$name;
                        $error = $errors->first($name);
                        $help = $field['data']['help'] ?? null;
                        $span = ($field['data']['width'] ?? 'full') === 'half' ? '' : 'sm:col-span-2';
                        $options = (array) ($field['data']['options'] ?? []);
                        // The field's note (its error, else its help), read out with it.
                        $note = ($error || $help) ? $id.'-note' : null;
                        $described = trim(($note ? 'aria-describedby="'.$note.'"' : '').($error ? ' aria-invalid="true"' : ''));
                        $choices = in_array($field['type'], ['radio', 'checkboxes'], true);
                    @endphp

                    @php
                        $showIf = filled($field['data']['show_if_field'] ?? null) ? [$field['data']['show_if_field'], (string) ($field['data']['show_if_value'] ?? '')] : null;
                    @endphp
                    {{-- Hidden for other answers, and then not sent (or asked for). --}}
                    <fieldset
                        class="{{ $span }} min-w-0"
                        @if ($showIf && ! $editing)
                            x-show="shows(@js($showIf[0]), @js($showIf[1]))"
                            x-bind:disabled="! shows(@js($showIf[0]), @js($showIf[1]))"
                            x-cloak
                        @endif
                    >
                        @if (! FormFields::isBuiltIn($field['type']))
                            {{-- A type a package or this site registered: it draws its own control. --}}
                            @if (FormFields::find($field['type'])::labelled())
                                <label for="{{ $id }}" class="mb-1.5 block text-sm font-medium text-zinc-900">
                                    {{ $field['label'] }}@if ($field['required'])<span class="text-rose-500"> *</span>@endif
                                </label>
                            @endif
                            <x-aurora::form-field :field="$field" :id="$id" :described="$described" />
                        @elseif ($field['type'] === 'consent')
                            <label class="flex items-start gap-3 text-sm text-zinc-700">
                                <input type="checkbox" name="{{ $name }}" value="1" @checked(old($name)) {!! $described !!} class="mt-0.5 size-4 rounded border-zinc-300 text-brand-600 focus:ring-brand-500">
                                <span>{{ $field['data']['text'] ?? $field['label'] }}@if ($field['required'])<span class="text-rose-500"> *</span>@endif</span>
                            </label>
                        @elseif ($choices)
                            {{-- A group of choices: a fieldset, named by its legend. --}}
                            <fieldset {!! $described !!}>
                                <legend class="mb-1.5 block text-sm font-medium text-zinc-900">
                                    {{ $field['label'] }}@if ($field['required'])<span class="text-rose-500"> *</span>@endif
                                </legend>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($options as $option)
                                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-full px-4 py-2 text-sm text-zinc-700 ring-1 ring-zinc-300 has-checked:bg-brand-50 has-checked:text-brand-700 has-checked:ring-brand-400 has-focus-visible:ring-2 has-focus-visible:ring-brand-600">
                                            <input
                                                type="{{ $field['type'] === 'radio' ? 'radio' : 'checkbox' }}"
                                                name="{{ $field['type'] === 'radio' ? $name : $name.'[]' }}"
                                                value="{{ $option }}"
                                                @checked(in_array($option, (array) old($name, []), true))
                                                class="sr-only"
                                            >
                                            {{ $option }}
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @else
                            <label for="{{ $id }}" class="mb-1.5 block text-sm font-medium text-zinc-900">
                                {{ $field['label'] }}@if ($field['required'])<span class="text-rose-500"> *</span>@endif
                            </label>

                            @switch($field['type'])
                                @case('textarea')
                                    <textarea id="{{ $id }}" name="{{ $name }}" {!! $described !!} rows="5" placeholder="{{ $field['data']['placeholder'] ?? '' }}" @required($field['required']) class="{{ $input }}">{{ old($name) }}</textarea>
                                    @break
                                @case('select')
                                    <select id="{{ $id }}" name="{{ $name }}" {!! $described !!} @required($field['required']) class="{{ $input }}">
                                        <option value="">{{ __('Choose…') }}</option>
                                        @foreach ($options as $option)
                                            <option value="{{ $option }}" @selected(old($name) === $option)>{{ $option }}</option>
                                        @endforeach
                                    </select>
                                    @break
                                @case('file')
                                    <input id="{{ $id }}" type="file" name="{{ $name }}" {!! $described !!} @required($field['required']) class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-full file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
                                    @break
                                @default
                                    <input
                                        id="{{ $id }}"
                                        {!! $described !!}
                                        type="{{ match ($field['type']) { 'email' => 'email', 'phone' => 'tel', 'date' => 'date', default => 'text' } }}"
                                        name="{{ $name }}"
                                        value="{{ old($name) }}"
                                        placeholder="{{ $field['data']['placeholder'] ?? '' }}"
                                        @required($field['required'])
                                        @if ($field['type'] === 'email') autocomplete="email" @endif
                                        class="{{ $input }}"
                                    >
                            @endswitch
                        @endif

                        @if ($error)
                            <p id="{{ $note }}" class="mt-1.5 text-sm text-rose-600">{{ $error }}</p>
                        @elseif ($help)
                            <p id="{{ $note }}" class="mt-1.5 text-sm text-zinc-500">{{ $help }}</p>
                        @endif
                    </fieldset>
                @endforeach

                <div class="sm:col-span-2">
                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-brand-600 px-6 py-3.5 font-semibold text-white shadow-lg shadow-brand-600/25 transition hover:bg-brand-500 sm:w-auto">
                        {{ $form->submit_label ?: __('Send') }}
                        {{ svg('hero-m-paper-airplane', 'size-4') }}
                    </button>
                </div>
            </form>
        @endif
    </div>
@endif
