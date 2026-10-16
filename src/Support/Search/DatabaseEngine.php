<?php

namespace Aurora\Support\Search;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\DatabaseEngine as ScoutDatabaseEngine;

/**
 * Scout's database engine (the default when no search service is set up), with full-text
 * search on MySQL and MariaDB for models that name their full-text columns (a FULLTEXT
 * index, see fullTextColumns()): words match whole or by their start ("photo" finds
 * "photos"), most relevant first. On other databases it searches as Scout's does.
 */
class DatabaseEngine extends ScoutDatabaseEngine
{
    protected function getFullTextColumns(Builder $builder)
    {
        return $this->usesFullText($builder) ? $builder->model->fullTextColumns() : parent::getFullTextColumns($builder);
    }

    protected function getFullTextOptions(Builder $builder)
    {
        return $this->usesFullText($builder) ? ['mode' => 'boolean'] : parent::getFullTextOptions($builder);
    }

    protected function addTextSearchConstraints($query, Builder $builder, array $columns, array $prefixColumns = [], array $fullTextColumns = [])
    {
        if ($this->usesFullText($builder) && filled($builder->query)) {
            $builder = clone $builder;
            $builder->query = static::booleanQuery($builder->query);
        }

        return parent::addTextSearchConstraints($query, $builder, $columns, $prefixColumns, $fullTextColumns);
    }

    /**
     * What someone typed, as a boolean full-text search: each word, matched by its start,
     * with the search's own operators taken out.
     */
    public static function booleanQuery(string $search): string
    {
        $words = preg_split('/[^\pL\pN]+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(fn (string $word): string => $word.'*', $words));
    }

    protected function usesFullText(Builder $builder): bool
    {
        return method_exists($builder->model, 'fullTextColumns') && in_array($builder->modelConnectionType(), ['mysql', 'mariadb'], true);
    }
}
