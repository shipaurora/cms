{{-- A form field of a type a website or package registered (Aurora\Support\FormFields::register()),
     drawn by the type itself (FieldType::render()), with its scripts loaded once per page. A
     website's form view draws Aurora's own types itself and hands any other type to this:
     <x-aurora::form-field :field="$field" :id="$id" :described="$described" /> --}}
@props(['field', 'id', 'described' => ''])

@php
    use Aurora\Support\FormFields;

    $type = FormFields::find($field['type']);
    $control = $type::render($field, $id, trim('id="'.e($id).'" name="'.e($field['name']).'" '.$described));
@endphp

{!! $control !!}
@foreach (FormFields::scriptsOnce($field['type']) as $script)
    <script src="{{ $script }}" async defer></script>
@endforeach
