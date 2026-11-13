<?php

/*
 * Ridge — a long thread shows its own high ground.
 */

use ErnestDefoe\Ridge\Api\Controller\ShowMarksController;
use ErnestDefoe\Ridge\Api\Controller\ShowRidgeController;
use ErnestDefoe\Ridge\Api\Controller\ShowYearsController;
use ErnestDefoe\Ridge\Listener\ForgetYears;
use ErnestDefoe\Ridge\Search\YearFilter;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Extend;
use Flarum\Search\Database\DatabaseSearchDriver;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/ridge/{id}', 'ridge.show', ShowRidgeController::class)
        ->get('/ridge/{id}/posts', 'ridge.posts', ShowMarksController::class)
        ->get('/ridge-years', 'ridge.years', ShowYearsController::class),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addFilter(DiscussionSearcher::class, YearFilter::class),

    // 🚨 Class names as strings: approval and tags may not be installed, and a
    // listener for an event that never fires costs nothing.
    (new Extend\Event())
        ->listen('Flarum\\Discussion\\Event\\Started', ForgetYears::class)
        ->listen('Flarum\\Discussion\\Event\\Deleted', ForgetYears::class)
        ->listen('Flarum\\Discussion\\Event\\Hidden', ForgetYears::class)
        ->listen('Flarum\\Discussion\\Event\\Restored', ForgetYears::class)
        ->listen('Flarum\\Post\\Event\\Posted', ForgetYears::class)
        ->listen('Flarum\\Approval\\Event\\PostWasApproved', ForgetYears::class)
        ->listen('Flarum\\Tags\\Event\\DiscussionWasTagged', ForgetYears::class),

    (new Extend\Settings())
        ->serializeToForum('ridgeMinPosts', 'ridge.min_posts', fn ($value) => (int) ($value ?: 12))
        ->serializeToForum('ridgePostMarks', 'ridge.post_marks', 'boolval')
        ->serializeToForum('ridgeBigNumber', 'ridge.big_number', 'boolval')
        ->serializeToForum('ridgeYears', 'ridge.years', 'boolval')
        ->serializeToForum('ridgeYearsPages', 'ridge.years_pages', fn ($value) => in_array($value, ['index', 'tags'], true) ? $value : 'both')
        ->serializeToForum('ridgeYearsMin', 'ridge.years_min', fn ($value) => max(1, (int) ($value ?: 2)))
        ->default('ridge.years_date', 'created'),
];
