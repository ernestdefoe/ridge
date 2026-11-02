<?php

namespace ErnestDefoe\Ridge;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Where a thread's high ground is.
 *
 * Everything here is already in the database — who quoted what, what was
 * reacted to, what people marked, which post answered the question. None of it
 * is curated, nobody votes, and no new content type exists. The thread has had
 * a shape all along; this reads it off.
 */
class Heat
{
    /**
     * What each signal is worth.
     *
     * Being quoted is the strongest ordinary signal a forum produces: it costs
     * the quoter something, and it is a direct statement that THESE words
     * mattered. A reaction is cheap by design, so it is worth the least. A
     * highlight sits between: nobody marks a passage for somebody else's
     * benefit, but it costs less than writing a reply.
     *
     * An accepted answer is not scored against the others at all — it is given
     * a floor, because a scrubber that buries the resolution of a 200-post
     * thread has failed at the one job it has.
     */
    private const QUOTE = 3;
    private const MARK = 2;
    private const REACTION = 1;
    private const ANSWER_FLOOR = 0.75;

    /** The tables Ridge reads, none of which it owns. */
    private const TABLES = ['post_mentions_post', 'post_likes', 'post_reactions', 'marginalia_highlights'];

    public function __construct(
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings,
        private Cache $cache
    ) {
    }

    /**
     * Which of those tables exist, asked of the schema once and then cached.
     *
     * A table only appears or goes when an extension's migrations run, which
     * happens on enable — so the answer is keyed on the enabled-extensions
     * list (an already-loaded setting) and a new list asks again by itself.
     *
     * @return array<string, bool>
     */
    private function tables(): array
    {
        $key = 'ridge.tables.'.md5((string) $this->settings->get('extensions_enabled'));

        return $this->cache->remember($key, 86400, function () {
            $schema = $this->db->getSchemaBuilder();

            return array_combine(self::TABLES, array_map(fn ($table) => $schema->hasTable($table), self::TABLES));
        });
    }

    public function for(Discussion $discussion, User $actor): array
    {
        $minimum = (int) ($this->settings->get('ridge.min_posts') ?: 12);

        /** @var array<int, int> post id => post number */
        $numbers = Post::query()
            ->where('discussion_id', $discussion->id)
            ->where('type', 'comment')
            ->whereVisibleTo($actor)
            ->orderBy('number')
            ->pluck('number', 'id')
            ->all();

        $last = $numbers ? max($numbers) : 0;

        // A short thread has no shape worth drawing, and a scrubber the reader
        // can see the whole of does not need a map of itself.
        if (count($numbers) < $minimum) {
            return ['enough' => false, 'last' => $last, 'peaks' => [], 'signals' => []];
        }

        $scores = array_fill_keys(array_keys($numbers), 0.0);
        $signals = [];
        $tables = $this->tables();

        // 🚨 The signal tables are matched against this discussion's posts with
        // a subquery, not a list of ids: a thread of thousands of posts would
        // otherwise send thousands of bound parameters four times over (and
        // SQLite caps them). Visibility is applied in PHP below — a post the
        // actor cannot see has no key in $scores, so its signals are skipped.
        $posts = $this->db->table('posts')
            ->select('id')
            ->where('discussion_id', $discussion->id)
            ->where('type', 'comment');

        // 🚨 Every count goes through the query builder, which applies the
        // forum's table prefix. A raw SQL string would not, and this extension
        // reads four tables it does not own.
        $add = function (string $table, string $column, float $weight, ?callable $filter = null) use (&$scores, &$signals, $tables, $posts) {
            if (empty($tables[$table])) {
                return;
            }

            $query = $this->db->table($table)
                ->select($column, $this->db->raw('COUNT(*) as total'))
                ->whereIn($column, $posts)
                ->groupBy($column);

            if ($filter) {
                $filter($query);
            }

            $found = 0;

            foreach ($query->get() as $row) {
                if (! isset($scores[$row->{$column}])) {
                    continue;
                }

                $scores[$row->{$column}] += $weight * (float) $row->total;
                $found += (int) $row->total;
            }

            $signals[$table] = $found;
        };

        // Quoted BY somebody else: the mentions pivot points from the quoting
        // post to the quoted one, so the post being counted is the target.
        $add('post_mentions_post', 'mentions_post_id', self::QUOTE);

        $add('post_likes', 'post_id', self::REACTION);
        $add('post_reactions', 'post_id', self::REACTION);

        // Public marks only — a private mark is its author's business, and
        // feeding it into a gradient everybody can see would publish it.
        $add('marginalia_highlights', 'post_id', self::MARK, fn ($query) => $query->where('is_public', true));

        $peak = max($scores) ?: 1.0;

        $peaks = [];

        // fof/best-answer's column; absent when that extension isn't installed.
        $answer = (int) $discussion->getAttribute('best_answer_post_id');

        foreach ($numbers as $id => $number) {
            $value = $scores[$id] / $peak;

            if ($answer && $answer === (int) $id) {
                $value = max($value, self::ANSWER_FLOOR);
            }

            if ($value > 0) {
                $peaks[] = ['number' => (int) $number, 'value' => round($value, 3)];
            }
        }

        return [
            'enough' => true,
            'last' => $last,
            'peaks' => $peaks,
            'signals' => $signals,
        ];
    }
}
