<?php

namespace ErnestDefoe\Ridge\Search;

use ErnestDefoe\Ridge\Years;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * `filter[ridgeYear]=2015`: the discussions of one year, from the year rail.
 *
 * 🚨 A range, never `YEAR(column) = ?`. A function around the column hides it
 * from its index; a half-open range on the bare column is answered by the
 * index on every database, and needs no per-driver SQL.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class YearFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function __construct(private Years $years)
    {
    }

    public function getFilterKey(): string
    {
        return 'ridgeYear';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $year = $this->asInt($value);

        if ($year < 1970 || $year > 9998) {
            return;
        }

        $column = 'discussions.'.$this->years->column();
        $from = sprintf('%04d-01-01 00:00:00', $year);
        $to = sprintf('%04d-01-01 00:00:00', $year + 1);

        $range = function ($query) use ($column, $from, $to) {
            $query->where($column, '>=', $from)->where($column, '<', $to);
        };

        $negate ? $state->getQuery()->whereNot($range) : $state->getQuery()->where($range);
    }
}
