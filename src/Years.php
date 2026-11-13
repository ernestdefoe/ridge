<?php

namespace ErnestDefoe\Ridge;

use ErnestDefoe\Ridge\Search\ListQuery;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Search\Database\DatabaseSearchDriver;
use Flarum\Search\SearchCriteria;
use Flarum\Search\SearchManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * How many discussions each year holds, for the year rail.
 *
 * 🚨 Built from the discussion SEARCHER, not from `Discussion::query()`.
 *
 * The rail has to agree with the list beside it: the same tag, the same
 * search, the same "following" filter, the same visibility, and whatever any
 * other extension's filters or mutators add. The searcher is where all of that
 * already lives, so the rail runs exactly the query the list runs — minus the
 * sort and the page — and counts it by year in one grouped query on top.
 */
class Years
{
    /** Bumped by the listeners whenever a discussion appears or goes. */
    public const GENERATION = 'ridge.years.generation';

    private const TTL = 600;

    public function __construct(
        private SearchManager $search,
        private SettingsRepositoryInterface $settings,
        private Cache $cache
    ) {
    }

    /**
     * The column a year is read from: when the discussion started (the
     * default) or when it was last active.
     */
    public function column(): string
    {
        return $this->settings->get('ridge.years_date') === 'activity' ? 'last_posted_at' : 'created_at';
    }

    /**
     * @param array<string, string|array> $filters the list's own filters
     * @return list<array{0: int, 1: int, 2: int}>|null [year, discussions, comments], newest first;
     *                                                  null when a search engine other than the database answers the search
     */
    public function for(User $actor, array $filters): ?array
    {
        unset($filters['ridgeYear']);
        ksort($filters);

        $column = $this->column();
        $fulltext = isset($filters['q']) && $filters['q'] !== '';

        if ($fulltext && $this->search->driverFor(Discussion::class)::name() !== DatabaseSearchDriver::name()) {
            return null;
        }

        // 🚨 Searches are counted fresh, never cached: every phrase would be
        // a cache entry of its own that nobody asks for twice.
        if ($fulltext) {
            return $this->count($actor, $filters, $column);
        }

        /*
         * 🚨 Keyed on WHO is asking, not on their groups.
         *
         * Two members of the same groups can still see different discussions:
         * their own unapproved ones, private discussions they were invited to.
         * Guests all see the same forum, so they share one entry. The
         * generation makes every key stale at once when a discussion starts,
         * goes, is hidden, restored, approved or re-tagged.
         */
        $generation = $this->cache->get(self::GENERATION, '0');
        $key = 'ridge.years.'.sha1(json_encode([$generation, $actor->isGuest() ? 0 : $actor->id, $column, $filters]));

        return $this->cache->remember($key, self::TTL, fn () => $this->count($actor, $filters, $column));
    }

    public function forget(): void
    {
        $this->cache->forever(self::GENERATION, (string) microtime(true));
    }

    /**
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private function count(User $actor, array $filters, string $column): array
    {
        $searcher = $this->search->driver(DatabaseSearchDriver::name())->searcher(Discussion::class);

        if (! $searcher instanceof DiscussionSearcher) {
            return [];
        }

        $list = ListQuery::of($searcher, new SearchCriteria($actor, $filters))->toBase();

        // The list's order is the reader's sort; a count needs none of it.
        $list->orders = $list->unionOrders = null;
        $list->limit = $list->offset = $list->unionLimit = $list->unionOffset = null;

        $connection = $list->getConnection();
        $grammar = $connection->getQueryGrammar();
        $date = $grammar->wrap($column);

        /*
         * 🚨 The one piece of SQL every database spells differently.
         *
         * The column is unqualified and grammar-wrapped: it is read from the
         * derived table below, whose alias Laravel prefixes the same way it
         * prefixes every table, so a forum with a table prefix still finds it.
         */
        $year = match ($connection->getDriverName()) {
            'pgsql' => "CAST(EXTRACT(YEAR FROM $date) AS INTEGER)",
            'sqlite' => "CAST(strftime('%Y', $date) AS INTEGER)",
            default => "YEAR($date)",
        };

        // A derived table rather than a GROUP BY bolted onto the list query:
        // a search or another extension's mutator may have turned that query
        // into a union, which cannot be grouped in place. MySQL, PostgreSQL
        // and SQLite all merge a plain one back into a single scan.
        return $list->newQuery()
            ->fromSub($list, 'ridge_years')
            ->selectRaw("$year as year, COUNT(*) as discussions, COALESCE(SUM(".$grammar->wrap('comment_count').'), 0) as comments')
            ->whereNotNull($column)
            ->groupByRaw($year)
            ->orderByRaw("$year desc")
            ->get()
            ->map(fn ($row) => [(int) $row->year, (int) $row->discussions, (int) $row->comments])
            ->all();
    }
}
