<?php

namespace Aurora\Support;

use Aurora\Enums\RevisionSource;
use Closure;

/**
 * Which editing surface is saving right now, so a revision can say where it came from.
 *
 * The visual editor, the page form and the MCP server all save the same model. The
 * observer cannot tell them apart, so the other surfaces run their save inside `as()`;
 * a save outside one counts as the page form (the Editor source).
 */
class RevisionContext
{
    protected static ?RevisionSource $source = null;

    protected static ?string $summary = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function as(RevisionSource $source, ?string $summary, Closure $callback): mixed
    {
        [$previousSource, $previousSummary] = [static::$source, static::$summary];

        static::$source = $source;
        static::$summary = $summary;

        try {
            return $callback();
        } finally {
            static::$source = $previousSource;
            static::$summary = $previousSummary;
        }
    }

    public static function source(): RevisionSource
    {
        return static::$source ?? RevisionSource::Editor;
    }

    public static function summary(): ?string
    {
        return static::$summary;
    }
}
