<?php

namespace Aurora\Support\Search;

use Laravel\Scout\Builder;

/**
 * A Scout search that keeps the limits it starts with (Page::search(): the site, and only what
 * visitors may see): query() adds to them instead of replacing them, so
 * ->query(fn ($query) => $query->with('author')) can't bring drafts or other sites back.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class ScopedBuilder extends Builder
{
    /**
     * @param  callable  $callback
     */
    public function query($callback)
    {
        $previous = $this->queryCallback;

        $this->queryCallback = $previous === null ? $callback : function ($query) use ($previous, $callback): void {
            $previous($query);
            $callback($query);
        };

        return $this;
    }
}
