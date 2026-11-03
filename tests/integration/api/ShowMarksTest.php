<?php

namespace ErnestDefoe\Ridge\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class ShowMarksTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-ridge');

        $posts = [];

        // Discussion 11: comments from two authors, an event post at #3, the
        // last comment hidden.
        for ($n = 1; $n <= 6; $n++) {
            $posts[] = ['id' => 1000 + $n, 'discussion_id' => 11, 'number' => $n, 'created_at' => Carbon::parse('2026-06-0'.$n), 'user_id' => $n % 2 ? 1 : 2, 'type' => 'comment', 'content' => '<t><p>Post '.$n.'</p></t>'];
        }
        $posts[2]['type'] = 'discussionRenamed';
        $posts[2]['content'] = '["Old","New"]';
        $posts[5]['hidden_at'] = Carbon::now();

        // A post in another thread, which must never show up here.
        $posts[] = ['id' => 1100, 'discussion_id' => 12, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Elsewhere</p></t>'];

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                ['id' => 11, 'title' => 'Thread', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 1001, 'comment_count' => 5],
                ['id' => 12, 'title' => 'Other', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1100, 'comment_count' => 1],
                ['id' => 13, 'title' => 'Hidden', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => null, 'comment_count' => 0, 'hidden_at' => Carbon::now()],
            ],
            Post::class => $posts,
        ]);
    }

    private function marks(int $discussion, ?int $actor = null): array
    {
        $response = $this->send($this->request('GET', "/api/ridge/$discussion/posts", $actor ? ['authenticatedAs' => $actor] : []));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function every_visible_post_is_listed_with_its_author_and_time()
    {
        [$status, $body] = $this->marks(11);

        $this->assertSame(200, $status);

        $rows = $body['data']['attributes']['posts'];

        $this->assertSame([1, 2, 3, 4, 5], array_column($rows, 1), 'Post numbers in order, the hidden #6 left out');
        $this->assertSame([1, 1], [$rows[0][2], $rows[2][2]]);
        $this->assertSame(Carbon::parse('2026-06-02')->getTimestamp(), $rows[1][3]);
        $this->assertSame([0, 0, 1, 0, 0], array_column($rows, 4), 'Only the rename is an event');

        $this->assertSame(['1' => 'admin', '2' => 'normal'], $body['data']['attributes']['users']);
    }

    #[Test]
    public function a_moderator_sees_the_hidden_post_too()
    {
        [, $body] = $this->marks(11, 1);

        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($body['data']['attributes']['posts'], 1));
    }

    #[Test]
    public function a_discussion_the_actor_cannot_see_is_not_found()
    {
        [$status] = $this->marks(13);
        $this->assertSame(404, $status);

        [$status] = $this->marks(999);
        $this->assertSame(404, $status);
    }

    #[Test]
    public function the_query_count_does_not_grow_with_the_thread()
    {
        $count = function (): int {
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->marks(11);
            $total = count(array_filter($db->getQueryLog(), fn ($q) => str_contains($q['query'], 'posts') || str_contains($q['query'], 'users')));
            $db->disableQueryLog();

            return $total;
        };

        $small = $count();

        $more = [];
        for ($n = 7; $n <= 40; $n++) {
            $more[] = ['id' => 1000 + $n, 'discussion_id' => 11, 'number' => $n, 'created_at' => Carbon::now(), 'user_id' => $n % 2 ? 1 : 2, 'type' => 'comment', 'content' => '<t><p>More</p></t>'];
        }
        $this->database()->table('posts')->insert($more);

        $this->assertSame($small, $count(), 'Forty posts cost the same queries as six');
    }
}
