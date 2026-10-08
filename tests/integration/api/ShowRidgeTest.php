<?php

namespace ErnestDefoe\Ridge\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;

class ShowRidgeTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-ridge');

        $posts = [];

        // Discussion 1: a long thread of 14 comments, the 14th hidden.
        for ($n = 1; $n <= 14; $n++) {
            $posts[] = ['id' => $n, 'discussion_id' => 1, 'number' => $n, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Post '.$n.'</p></t>'];
        }
        $posts[13]['hidden_at'] = Carbon::now();

        // Discussion 2: a short thread.
        $posts[] = ['id' => 100, 'discussion_id' => 2, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Short</p></t>'];

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                ['id' => 1, 'title' => 'Long', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 13],
                ['id' => 2, 'title' => 'Short', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 100, 'comment_count' => 1],
                ['id' => 3, 'title' => 'Hidden', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => null, 'comment_count' => 0, 'hidden_at' => Carbon::now()],
            ],
            Post::class => $posts,
        ]);
    }

    private function ridge(int $discussion, ?int $actor = null): array
    {
        $response = $this->send($this->request('GET', "/api/ridge/$discussion", $actor ? ['authenticatedAs' => $actor] : []));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /** The quote pivot belongs to flarum/mentions, which these tests don't install. */
    private function quotes(array $quotedBy): void
    {
        $schema = $this->database()->getSchemaBuilder();

        if (! $schema->hasTable('post_mentions_post')) {
            $schema->create('post_mentions_post', function (Blueprint $table) {
                $table->unsignedInteger('post_id');
                $table->unsignedInteger('mentions_post_id');
            });
        }

        foreach ($quotedBy as $quoted => $quoters) {
            foreach ($quoters as $quoter) {
                $this->database()->table('post_mentions_post')->insert(['post_id' => $quoter, 'mentions_post_id' => $quoted]);
            }
        }
    }

    #[Test]
    public function a_short_thread_has_no_shape_to_draw()
    {
        [$status, $body] = $this->ridge(2);

        $this->assertSame(200, $status);
        $this->assertFalse($body['data']['attributes']['enough']);
        $this->assertSame(1, $body['data']['attributes']['last']);
    }

    #[Test]
    public function a_discussion_the_actor_cannot_see_is_not_found()
    {
        [$status] = $this->ridge(3);
        $this->assertSame(404, $status, 'A guest cannot read a hidden discussion\'s ridge');

        [$status] = $this->ridge(999);
        $this->assertSame(404, $status);
    }

    #[Test]
    public function hidden_posts_are_left_out_for_those_who_cannot_see_them()
    {
        // 13 visible comments: enough for the default minimum of 12. The
        // hidden 14th is neither counted nor reported as the last post.
        [, $body] = $this->ridge(1);
        $this->assertTrue($body['data']['attributes']['enough']);
        $this->assertSame(13, $body['data']['attributes']['last']);
    }

    #[Test]
    public function hidden_posts_do_not_count_toward_the_minimum()
    {
        $this->setting('ridge.min_posts', 14);

        [, $body] = $this->ridge(1);
        $this->assertFalse($body['data']['attributes']['enough'], 'Only 13 of the 14 comments are visible');
    }

    #[Test]
    public function the_most_quoted_post_is_the_peak()
    {
        $this->app();
        // Post 5 quoted three times, post 9 once.
        $this->quotes([5 => [6, 7, 8], 9 => [10]]);

        [$status, $body] = $this->ridge(1);

        $this->assertSame(200, $status);
        $peaks = array_column($body['data']['attributes']['peaks'], 'value', 'number');
        $this->assertEquals(1.0, $peaks[5]);
        $this->assertEquals(round(1 / 3, 3), $peaks[9]);
        $this->assertCount(2, $peaks, 'Unquoted posts have no heat');
        $this->assertSame(4, $body['data']['attributes']['signals']['post_mentions_post']);
    }
}
