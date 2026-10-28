<?php

namespace Aurora\Forms\Fields;

use Aurora\Forms\FieldType;
use Aurora\Models\FormSubmission;
use Aurora\Support\FormFields;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * An uploaded file, kept privately with the submission (its "files" media), not as an answer.
 * Only the types it lists, never ones a browser would run (FormFields::acceptedExtensions()).
 */
class File extends FieldType
{
    public static function type(): string
    {
        return 'file';
    }

    public static function label(): string
    {
        return __('File upload');
    }

    public static function icon(): string
    {
        return 'phosphor-paperclip';
    }

    public static function settings(): array
    {
        return [
            TextInput::make('accept')->label(__('Allowed types'))->placeholder('pdf, jpg, png')->helperText(__('File extensions, comma separated.')),
            TextInput::make('max_mb')->label(__('Max size (MB)'))->numeric()->minValue(1)->maxValue(FormFields::MAX_FILE_MB)->default(10),
        ];
    }

    public static function translatable(): array
    {
        return [];
    }

    public static function rules(array $field): array
    {
        return [
            $field['required'] ? 'required' : 'nullable',
            'file',
            'max:'.(int) (($field['data']['max_mb'] ?? 10) * 1024),
            'mimes:'.implode(',', FormFields::acceptedExtensions($field['data']['accept'] ?? null)),
        ];
    }

    public static function stores(): bool
    {
        return false;
    }

    public static function afterSaving(array $field, Request $request, FormSubmission $submission): void
    {
        if (($file = $request->file($field['name'])) instanceof UploadedFile) {
            $submission->addMedia($file)->withCustomProperties(['field' => $field['name'], 'label' => $field['label']])->toMediaCollection('files');
        }
    }

    public static function uploads(): bool
    {
        return true;
    }

    public static function publicData(): array
    {
        return [...parent::publicData(), 'accept', 'max_mb'];
    }
}
