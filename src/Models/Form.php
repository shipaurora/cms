<?php

namespace Aurora\Models;

use Aurora\Database\Factories\FormFactory;
use Aurora\Enums\SubmissionStatus;
use Aurora\Models\Concerns\BelongsToSite;
use Aurora\Support\ContentLocale;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

/**
 * A form editors build from typed fields (text, email, choices, consent, file …).
 *
 * The fields are stored as Filament Builder items: [{type, data: {label, name, …}}].
 * A "Form" block places it on any page; submissions are stored, emailed and triaged.
 */
#[Fillable(['name', 'fields', 'field_translations', 'submit_label', 'success_message', 'redirect_page_id', 'notify', 'is_active'])]
class Form extends Model
{
    /** @use HasFactory<FormFactory> */
    use BelongsToSite, HasFactory, HasTranslations, HasUuids, SoftDeletes;

    /**
     * Read and written in the content language: the page's on the website.
     *
     * @var array<int, string>
     */
    public array $translatable = ['submit_label', 'success_message'];

    /**
     * The text of a field that is translated; everything else (type, name, required…) is
     * the same in every language.
     *
     * @var array<int, string>
     */
    public const FIELD_TEXT = ['label', 'placeholder', 'help', 'options', 'text'];

    public function getLocale(): string
    {
        return $this->translationLocale ?: ContentLocale::current();
    }

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'field_translations' => 'array',
            'notify' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class)->latest();
    }

    public function newSubmissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class)->where('status', SubmissionStatus::New);
    }

    public function redirectPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'redirect_page_id');
    }

    /**
     * The language the fields are written in. Other languages keep only their text
     * (field_translations), the same way a page's blocks do.
     */
    public function sourceLocale(): string
    {
        return ContentLocale::authoredIn(array_keys(ContentLocale::all()), array_keys($this->field_translations ?? []));
    }

    /**
     * Same as a page's own language: the language the fields are written in.
     */
    public function primaryLocale(): string
    {
        return $this->sourceLocale();
    }

    /**
     * Fields as flat definitions, each with a stable input name, with their text in a
     * language (the content language by default; untranslated text stays the written language's).
     *
     * @return array<int, array{type: string, name: string, label: string, required: bool, data: array<string, mixed>}>
     */
    public function definitions(?string $locale = null): array
    {
        $locale ??= ContentLocale::current();
        $translations = $locale === $this->sourceLocale() ? [] : ($this->field_translations[$locale] ?? []);

        return collect($this->fields ?? [])
            ->filter(fn (mixed $field): bool => is_array($field) && is_string($field['type'] ?? null))
            ->map(function (array $field, int $index) use ($translations): array {
                $data = (array) ($field['data'] ?? []);
                $name = Str::snake((string) ($data['name'] ?? '')) ?: 'field_'.($index + 1);
                $text = array_filter(array_intersect_key((array) ($translations[$name] ?? []), array_flip(self::FIELD_TEXT)), fn (mixed $value): bool => filled($value));
                $data = array_replace($data, $text);

                return [
                    'type' => $field['type'],
                    'name' => $name,
                    'label' => (string) ($data['label'] ?? Str::headline($field['type'])),
                    'required' => (bool) ($data['required'] ?? false),
                    'data' => $data,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The live pages showing this form, in a Form block.
     *
     * @return Collection<int, Page>
     */
    public function livePages(): Collection
    {
        return Page::query()
            ->live()
            ->where('blocks', 'like', '%'.$this->getKey().'%')
            ->get()
            ->filter(fn (Page $page): bool => collect($page->blocks ?? [])->contains(
                fn (array $block): bool => ($block['type'] ?? null) === 'form' && ($block['data']['form_id'] ?? null) === $this->getKey(),
            ))
            ->values();
    }
}
