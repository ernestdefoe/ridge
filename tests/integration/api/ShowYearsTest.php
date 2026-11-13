<?php

namespace ErnestDefoe\Ridge\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class ShowYearsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private int $next = 100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-flags', 'flarum-approval', 'ernestdefoe-ridge');
        $this->setting('ridge.years', '1');

        $discussions = $posts = $tagged = [];

        /*
         * Public tag 1 ("open"), restricted tag 2 ("staff") that only admins
         * see. Per year, visible to a guest: 2026 ×2, 2019 ×1, 2012 ×3.
         */
        $rows = [
            // [created, last posted, tag, comments, extra]
            ['2026-03-01', '2026-03-02', 1, 4, []],
            ['2026-01-01 00:00:00', '2026-01-01', 1, 1, []],
            ['2019-12-31 23:59:59', '2026-05-01', 1, 9, []],
            ['2012-05-01', '2012-05-01', 1, 2, []],
            ['2012-06-01', '2012-06-01', 1, 2, []],
            ['2012-07-01', '2012-07-01', 1, 2, []],
            // Never counted for a guest:
            ['2016-01-01', '2016-01-01', 2, 5, []],                       // restricted tag
            ['2017-01-01', '2017-01-01', 1, 5, ['is_private' => 1]],       // private
            ['2018-01-01', '2018-01-01', 1, 5, ['hidden_at' => '2018-01-02']], // hidden
            ['2015-01-01', '2015-01-01', 1, 5, ['is_approved' => 0, 'is_private' => 1]], // awaiting approval
        ];

        foreach ($rows as $i => [$created, $last, $tag, $comments, $extra]) {
            $id = $i + 1;
            $discussions[] = $extra + ['id' => $id, 'title' => "D$id", 'slug' => "d$id", 'created_at' => Carbon::parse($created), 'last_posted_at' => Carbon::parse($last), 'user_id' => 2, 'first_post_id' => $id, 'comment_count' => $comments, 'is_private' => 0, 'is_approved' => 1];
            $posts[] = ['id' => $id, 'discussion_id' => $id, 'number' => 1, 'created_at' => Carbon::parse($created), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Ridgetoken'.$id.' words</p></t>', 'is_private' => $extra['is_private'] ?? 0, 'is_approved' => $extra['is_approved'] ?? 1];
            $tagged[] = ['discussion_id' => $id, 'tag_id' => $tag];
        }

        $this->prepareDatabase([
            User::class => [$this->normalUser(), ['id' => 3, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1]],
            'tags' => [
                ['id' => 1, 'name' => 'Open', 'slug' => 'open', 'position' => 0, 'is_restricted' => 0],
                ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => 1],
            ],
            Discussion::class => $discussions,
            Post::class => $posts,
            'discussion_tag' => $tagged,
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'startDiscussion'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'tag1.startDiscussion'],
            ],
        ]);
    }

    private function years(?int $actor = null, array $filter = []): array
    {
        $response = $this->send($this->request('GET', '/api/ridge-years', $actor ? ['authenticatedAs' => $actor] : [])->withQueryParams(['filter' => $filter]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data']['attributes']['years'];
    }

    #[Test]
    public function a_guest_counts_only_what_a_guest_can_see_newest_year_first()
    {
        // [year, discussions, comments]
        $this->assertSame([[2026, 2, 5], [2019, 1, 9], [2012, 3, 6]], $this->years());
    }

    #[Test]
    public function an_admin_counts_every_discussion()
    {
        // All but the plain private 2017 one, which only an extension like
        // Byobu can open to anyone — the list does not show it either.
        $this->assertSame([2026, 2019, 2018, 2016, 2015, 2012], array_column($this->years(1), 0));
    }

    #[Test]
    public function an_author_counts_their_own_discussion_awaiting_approval()
    {
        // User 2 wrote the unapproved 2015 one; user 3 did not.
        $this->assertContains(2015, array_column($this->years(2), 0));
        $this->assertNotContains(2015, array_column($this->years(3), 0));
    }

    #[Test]
    public function the_list_filters_apply_and_the_year_filter_is_ignored()
    {
        $this->assertSame([[2016, 1, 5]], $this->years(1, ['tag' => 'staff']));
        $this->assertSame([], $this->years(null, ['tag' => 'staff']), 'A guest cannot count a restricted tag');
        $this->assertSame([[2026, 2, 5], [2019, 1, 9], [2012, 3, 6]], $this->years(null, ['tag' => 'open', 'ridgeYear' => '2012']));
        $this->assertSame([], $this->years(null, ['author' => 'other']));
    }

    #[Test]
    public function a_search_counts_only_its_results()
    {
        // 🚨 InnoDB's fulltext index never sees rows the test's own
        // transaction has not committed, so MySQL and MariaDB find nothing
        // here; the search path runs on PostgreSQL and SQLite.
        if (in_array($this->database()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('InnoDB fulltext cannot see uncommitted test rows.');
        }

        $this->assertSame([[2012, 1, 2]], $this->years(null, ['q' => 'Ridgetoken5']));
    }

    #[Test]
    public function the_year_filter_lists_one_year_on_the_core_endpoint()
    {
        $list = fn (array $filter, ?int $actor = null) => $this->send($this->request('GET', '/api/discussions', $actor ? ['authenticatedAs' => $actor] : [])->withQueryParams(['filter' => $filter]));

        $ids = function (array $filter, ?int $actor = null) use ($list): array {
            $response = $list($filter, $actor);
            $body = json_decode((string) $response->getBody(), true);
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $ids = array_map('intval', array_column($body['data'], 'id'));
            sort($ids);

            return $ids;
        };

        // Both ends of the year: the first second of 2026 is in, the last second of 2019 is out.
        $this->assertSame([1, 2], $ids(['ridgeYear' => '2026']));
        $this->assertSame([3], $ids(['ridgeYear' => '2019']));
        $this->assertSame([], $ids(['ridgeYear' => '2016']), 'Visibility still applies inside a year');
        $this->assertSame([7], $ids(['ridgeYear' => '2016'], 1));
        $this->assertSame([1, 2, 3], $ids(['-ridgeYear' => '2012']));
        $this->assertSame(422, $list(['ridgeYear' => 'nonsense'])->getStatusCode());
    }

    #[Test]
    public function last_activity_mode_places_a_discussion_in_the_year_of_its_last_reply()
    {
        $this->setting('ridge.years_date', 'activity');

        // D3 started in 2019 but was last active in 2026.
        $this->assertSame([[2026, 3, 14], [2012, 3, 6]], $this->years());
    }

    #[Test]
    public function a_new_discussion_shows_up_at_once_despite_the_cache()
    {
        $this->assertSame(2, $this->years()[0][1]);

        $response = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Brand new', 'content' => 'Hello there, this year.'], 'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '1']]]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame([2026, 3], array_slice($this->years()[0], 0, 2));
    }

    #[Test]
    public function hiding_a_discussion_takes_it_off_at_once()
    {
        $this->assertSame(3, $this->years()[2][1]);

        $response = $this->send($this->request('PATCH', '/api/discussions/4', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'id' => '4', 'attributes' => ['isHidden' => true]]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame([2012, 2, 4], $this->years()[2]);
    }

    #[Test]
    public function the_rail_is_empty_when_switched_off()
    {
        $this->setting('ridge.years', '0');

        $this->assertSame([], $this->years());
    }

    #[Test]
    public function the_query_count_does_not_grow_with_the_forum()
    {
        $count = function (): int {
            $this->app()->getContainer()->make('cache.store')->flush();
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->years(2, ['tag' => 'open']);
            $total = count($db->getQueryLog());
            $db->disableQueryLog();

            return $total;
        };

        $count(); // the first request of a test also loads settings and permissions
        $small = $count();

        $discussions = $posts = $tagged = [];
        for ($id = 100; $id < 160; $id++) {
            $created = Carbon::create(2000 + $id % 26, 3, 1);
            $discussions[] = ['id' => $id, 'title' => "D$id", 'slug' => "d$id", 'created_at' => $created, 'last_posted_at' => $created, 'user_id' => 2, 'comment_count' => 1, 'is_private' => 0, 'is_approved' => 1];
            $posts[] = ['id' => $id, 'discussion_id' => $id, 'number' => 1, 'created_at' => $created, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>more words</p></t>', 'is_private' => 0, 'is_approved' => 1];
            $tagged[] = ['discussion_id' => $id, 'tag_id' => 1 + $id % 2];
        }
        $this->database()->table('discussions')->insert($discussions);
        $this->database()->table('posts')->insert($posts);
        $this->database()->table('discussion_tag')->insert($tagged);

        $this->assertSame($small, $count(), 'Seventy discussions over 26 years cost the same queries as ten');
    }
}
