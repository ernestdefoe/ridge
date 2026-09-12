<?php

/*
 * Ridge — a long thread shows its own high ground.
 */

use ErnestDefoe\Ridge\Api\Controller\ShowRidgeController;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/ridge/{id}', 'ridge.show', ShowRidgeController::class),

    (new Extend\Settings())
        ->serializeToForum('ridgeMinPosts', 'ridge.min_posts', fn ($value) => (int) ($value ?: 12)),
];
