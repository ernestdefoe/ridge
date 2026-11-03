<?php

namespace ErnestDefoe\Ridge;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * Who wrote each post, and when — enough to label a mark on the scrubber.
 *
 * Two queries whatever the length of the thread: the posts this reader can
 * see, then their authors in one go. The client keys the rows by post id, so
 * they line up with the stream's own list of ids however the two are ordered.
 */
class Marks
{
    public function for(Discussion $discussion, User $actor): array
    {
        $posts = Post::query()
            ->where('discussion_id', $discussion->id)
            ->whereVisibleTo($actor)
            ->orderBy('number')
            ->get(['id', 'number', 'user_id', 'created_at', 'type']);

        $authors = User::query()
            ->whereIn('id', $posts->pluck('user_id')->filter()->unique()->values())
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => $user->display_name]);

        // [id, number, author id, unix time, 1 when it is an event post] as
        // plain arrays: a thread of thousands of posts sends this once, and
        // keyed objects would repeat every field name on every row.
        $rows = [];

        foreach ($posts as $post) {
            $rows[] = [
                (int) $post->id,
                (int) $post->number,
                $post->user_id ? (int) $post->user_id : null,
                $post->created_at ? $post->created_at->getTimestamp() : null,
                $post->type === 'comment' ? 0 : 1,
            ];
        }

        return [
            'posts' => $rows,
            'users' => (object) $authors->all(),
        ];
    }
}
