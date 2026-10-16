<?php

namespace Aurora\Models;

use Aurora\Enums\RevisionSource;
use CarlJanzell\FilamentPageBuilder\Support\BlockTree;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['page_id', 'user_id', 'source', 'summary', 'snapshot'])]
class PageRevision extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'source' => RevisionSource::class,
            'snapshot' => 'array',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blockCount(): int
    {
        return count(BlockTree::hydrate($this->snapshot['blocks'] ?? []));
    }

    /**
     * The summary in the reader's language. The CMS writes its own summaries in English
     * ("Created the page", "Changed title, SEO"); those are translated here. Summaries an AI
     * assistant wrote are shown as written.
     */
    public function summaryText(): ?string
    {
        $summary = (string) $this->summary;

        return match (true) {
            blank($summary) => null,
            (bool) preg_match('/^Changed (.+)$/', $summary, $match) => __('Changed :things', ['things' => collect(explode(', ', $match[1]))->map(fn (string $thing): string => __($thing))->join(', ')]),
            (bool) preg_match('/^Translated into (.+)$/', $summary, $match) => __('Translated into :language', ['language' => $match[1]]),
            (bool) preg_match('/^Restored the version from (.+)$/', $summary, $match) => __('Restored the version from :date', [
                'date' => rescue(fn (): string => Carbon::parse($match[1])->locale(app()->getLocale())->isoFormat('lll'), $match[1], report: false),
            ]),
            default => __($summary),
        };
    }
}
