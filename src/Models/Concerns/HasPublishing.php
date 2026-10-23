<?php

namespace Aurora\Models\Concerns;

use Aurora\Enums\PageStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;

/**
 * Published like a page: a status (draft, in review, published), a publish date that can
 * schedule it, and an unpublish date that takes it down by itself. Pages use it, and so can a
 * website's own models (events, albums), with Aurora\Filament\Publishing for their admin.
 *
 * The table needs the columns publishingColumns() adds, in a migration:
 *
 *     Schema::create('events', function (Blueprint $table) {
 *         …
 *         Event::publishingColumns($table);
 *     });
 */
trait HasPublishing
{
    public function initializeHasPublishing(): void
    {
        $this->mergeCasts([
            'status' => PageStatus::class,
            'published_at' => 'datetime',
            'unpublish_at' => 'datetime',
        ]);
    }

    /**
     * The columns, for a migration: status (draft until published), publish and unpublish dates.
     */
    public static function publishingColumns(Blueprint $table): void
    {
        $table->string('status')->default(PageStatus::Draft->value)->index();
        $table->timestamp('published_at')->nullable()->index();
        $table->timestamp('unpublish_at')->nullable()->index();
    }

    /**
     * What visitors may see: published, the publish date has arrived, and the unpublish date
     * (if any) hasn't.
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), PageStatus::Published)
            ->where(fn (Builder $query) => $query->whereNull($this->qualifyColumn('published_at'))->orWhere($this->qualifyColumn('published_at'), '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull($this->qualifyColumn('unpublish_at'))->orWhere($this->qualifyColumn('unpublish_at'), '>', now()));
    }

    /**
     * Published, but the unpublish date has passed.
     */
    #[Scope]
    protected function ended(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), PageStatus::Published)->where($this->qualifyColumn('unpublish_at'), '<=', now());
    }

    public function isLive(): bool
    {
        return $this->status === PageStatus::Published
            && ($this->published_at === null || $this->published_at->isPast())
            && ! $this->hasEnded();
    }

    /**
     * Published, but its unpublish date has passed: it came down by itself.
     */
    public function hasEnded(): bool
    {
        return $this->status === PageStatus::Published && $this->unpublish_at !== null && ! $this->unpublish_at->isFuture();
    }

    /**
     * Published, with a publish date still to come.
     */
    public function isScheduled(): bool
    {
        return $this->status === PageStatus::Published && (bool) $this->published_at?->isFuture();
    }
}
