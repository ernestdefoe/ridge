<?php

namespace ErnestDefoe\Ridge\Listener;

use ErnestDefoe\Ridge\Years;
use Flarum\Post\Event\Posted;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Makes every cached year count stale when the set of discussions changes.
 *
 * A new reply only moves a discussion between years when the rail counts by
 * last activity, so in the default (started) mode replies leave the cache be.
 */
class ForgetYears
{
    public function __construct(
        private Years $years,
        private SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(object $event): void
    {
        if (! $this->settings->get('ridge.years')) {
            return;
        }

        if ($event instanceof Posted && ($event->post->number <= 1 || $this->years->column() !== 'last_posted_at')) {
            return;
        }

        $this->years->forget();
    }
}
