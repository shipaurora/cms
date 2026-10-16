<?php

namespace Aurora\Models;

use Aurora\Enums\SubmissionStatus;
use Aurora\Support\CmsNotifications;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One filled-in form. Answers are kept by field name, with the label at the time of
 * submitting, so renaming a field later does not make old submissions unreadable.
 * Uploaded files are attached through the media library, on the private local disk.
 */
#[Fillable(['form_id', 'page_id', 'data', 'status', 'ip_address', 'user_agent'])]
class FormSubmission extends Model implements HasMedia
{
    use HasUuids, InteractsWithMedia;

    /**
     * Opened (or marked read, archived or spam): the "new submission" notifications about it
     * are done with.
     */
    protected static function booted(): void
    {
        static::updated(function (self $submission): void {
            if ($submission->wasChanged('status') && $submission->status !== SubmissionStatus::New) {
                CmsNotifications::submissionHandled($submission);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'status' => SubmissionStatus::class,
        ];
    }

    /**
     * Files sent through the form are private: on the local disk, never at a public address.
     * They are downloaded through SubmissionFileController.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('files')->useDisk('local');
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function answers(): array
    {
        return collect($this->data ?? [])
            ->map(fn (mixed $answer, string $name): array => [
                'label' => (string) ($answer['label'] ?? $name),
                'value' => is_array($answer['value'] ?? null) ? implode(', ', $answer['value']) : (string) ($answer['value'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * The first answer that is an email address: where a reply goes.
     */
    public function replyAddress(): ?string
    {
        return collect($this->answers())->pluck('value')->first(fn (string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false);
    }

    /**
     * A short line for lists: the first couple of answers.
     */
    public function summary(): string
    {
        return collect($this->answers())->take(2)->pluck('value')->filter()->implode(' · ');
    }
}
