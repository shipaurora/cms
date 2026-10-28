<?php

namespace Aurora\Forms;

use Aurora\Models\FormSubmission;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\Request;

/**
 * One kind of field a form can have: how editors set it up in the form builder, how its answer
 * is checked and kept, and, for a website's or a package's own types, how it's drawn. Aurora's
 * own types are in Aurora\Forms\Fields; register others with FormFields::register() in a
 * service provider's boot(), such as a captcha or an address picker.
 *
 * A field reaches each method as its definition (Form::definitions()):
 * {type, name, label, required, data}, with data the editor's settings (placeholder, help,
 * width, options…, and the type's own from settings()).
 */
abstract class FieldType
{
    /**
     * Its key, stored with each field: lowercase, unique, never changed once forms use it.
     */
    abstract public static function type(): string;

    /**
     * Its name in the form builder's list of fields.
     */
    abstract public static function label(): string;

    /**
     * A Phosphor icon for the form builder ("phosphor-…").
     */
    public static function icon(): string
    {
        return 'phosphor-textbox';
    }

    /**
     * Its own settings in the form builder (Filament form components), between the field key and
     * the help text. Their values arrive in the field's data.
     *
     * @return array<int, mixed>
     */
    public static function settings(): array
    {
        return [];
    }

    /**
     * Whether a new field of this type starts as required.
     */
    public static function requiredByDefault(): bool
    {
        return false;
    }

    /**
     * Which of its text the form's translations offer, and how: 'text' (one line), 'textarea'
     * or 'options' (a list of choices). Only text the field has in the written language shows.
     *
     * @return array<string, string>
     */
    public static function translatable(): array
    {
        return ['placeholder' => 'text', 'help' => 'text'];
    }

    /**
     * Laravel validation rules for its answer, run before anything is kept. A captcha checks
     * its token here (a closure rule or a Rule object), so a failed check stops the submission.
     *
     * @param  array{type: string, name: string, label: string, required: bool, data: array<string, mixed>}  $field
     * @return array<int, mixed>
     */
    public static function rules(array $field): array
    {
        return [$field['required'] ? 'required' : 'nullable', 'string', 'max:255'];
    }

    /**
     * Whether its answer is kept with the submission (and shown in submissions, the CSV export
     * and the email). Not for a captcha, nor a file, which is kept as a file (afterSaving()).
     */
    public static function stores(): bool
    {
        return true;
    }

    /**
     * Its answer as kept: a string, or a list of strings for several choices.
     *
     * @param  array{type: string, name: string, label: string, required: bool, data: array<string, mixed>}  $field
     * @return string|array<int, string>|null
     */
    public static function value(array $field, Request $request): string|array|null
    {
        $value = $request->input($field['name']);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : (is_scalar($value) ? (string) $value : null);
    }

    /**
     * What to do once the submission is saved, such as keeping an uploaded file.
     *
     * @param  array{type: string, name: string, label: string, required: bool, data: array<string, mixed>}  $field
     */
    public static function afterSaving(array $field, Request $request, FormSubmission $submission): void {}

    /**
     * Whether the form has to be sent as multipart/form-data (it uploads a file).
     */
    public static function uploads(): bool
    {
        return false;
    }

    /**
     * Its settings the website and the APIs may see, from its data.
     *
     * @return array<int, string>
     */
    public static function publicData(): array
    {
        return ['placeholder', 'help', 'width'];
    }

    /**
     * Whether the website shows its label above it. Not for a captcha, which explains itself.
     */
    public static function labelled(): bool
    {
        return true;
    }

    /**
     * Its control on the website, for <x-aurora::form-field>: a website's or a package's own
     * type draws itself here; Aurora's own types are drawn by the website's form view and
     * return null. $attributes are the input's id, name and accessibility attributes, as HTML.
     *
     * @param  array{type: string, name: string, label: string, required: bool, data: array<string, mixed>}  $field
     */
    public static function render(array $field, string $id, string $attributes): Htmlable|string|null
    {
        return null;
    }

    /**
     * Scripts it needs on the page, as URLs, loaded once however many fields use them.
     *
     * @return array<int, string>
     */
    public static function scripts(): array
    {
        return [];
    }
}
