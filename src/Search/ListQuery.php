<?php

namespace ErnestDefoe\Ridge\Search;

use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchCriteria;
use Illuminate\Database\Eloquent\Builder;

/**
 * The query the discussion list would run, before it is sorted and paged.
 *
 * 🚨 The searcher's filters and mutators are protected, and it only offers
 * `search()`, which executes the query. This class is never instantiated: as a
 * subclass of the same AbstractSearcher it may read another searcher's
 * protected members, so it replays the steps `search()` takes up to the sort
 * against the forum's real searcher — no copy of core's logic to drift, and
 * every filter and mutator any extension registered still applies.
 */
abstract class ListQuery extends AbstractSearcher
{
    public static function of(DiscussionSearcher $searcher, SearchCriteria $criteria): Builder
    {
        $state = new DatabaseSearchState($criteria->actor, $criteria->isFulltext());
        $state->setQuery($searcher->getQuery($criteria->actor));

        $searcher->filters->apply($state, $criteria->filters);

        foreach ($searcher->mutators as $mutator) {
            $mutator($state, $criteria);
        }

        return $state->getQuery();
    }
}
