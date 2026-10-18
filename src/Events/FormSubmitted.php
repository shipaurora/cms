<?php

namespace Aurora\Events;

use Aurora\Models\FormSubmission;

/**
 * Someone sent a form on the website (not spam).
 */
class FormSubmitted extends ContentEvent
{
    public function __construct(public readonly FormSubmission $submission) {}

    public static function name(): string
    {
        return 'form.submitted';
    }

    public function payload(): array
    {
        return [
            'id' => $this->submission->id,
            'form' => ['id' => $this->submission->form_id, 'name' => $this->submission->form?->name],
            'page_id' => $this->submission->page_id,
            'fields' => collect($this->submission->data ?? [])->mapWithKeys(fn (array $field, string $name): array => [$name => $field['value'] ?? null])->all(),
            'submitted_at' => $this->submission->created_at?->toIso8601String(),
        ];
    }
}
