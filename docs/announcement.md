# Ridge — a long thread shows its own high ground (Built using AI)

A long thread has a shape. A handful of posts get quoted, reacted to and marked far more than the rest — the number everyone kept coming back to, the quote that settled it, the post where it was finally decided.

Nothing in Flarum shows you where those are. You scroll, and hope.

Ridge reads the signals the forum already holds and draws them as a strip of shading down the post scrubber.

![The ridge beside the scrubber](https://raw.githubusercontent.com/ernestdefoe/ridge/main/screenshots/scrubber.png)

Three bands, three places that thread actually turned: the budget everyone quoted, the quote that settled the venue, and the post that booked it. Nobody curated any of that. Nobody voted. It was already in the database.

## What it reads

- **Being quoted** — weight 3. The strongest ordinary signal a forum makes: it costs the quoter something, and it says *these words* mattered.
- **Being marked publicly** — weight 2, via [Marginalia](https://github.com/ernestdefoe/marginalia). Nobody highlights a passage for somebody else's benefit. Private marks are never counted.
- **Reactions** — weight 1, from `flarum/likes` or `fof/reactions`. Cheap by design, so worth the least.
- **An accepted answer** — not scored against the others at all. It gets a guaranteed peak, because a scrubber that buries the resolution of a 200-post thread has failed at its one job.

Every signal is optional. Ridge checks which tables exist and counts what it finds, so a forum with none of those extensions installed simply has less to draw.

## Decisions worth knowing about

**Shading, not markers.** [Threadmarks](https://discuss.flarum.org/d/39803) and friends put pins *on* the scrubber. A second extension adding pins to the same forty pixels would be a fight the reader loses, so Ridge paints the track instead — the two can be installed together and each still reads.

**A strip at the edge, not a wash behind the track.** Full-width shading looked good in the theme I built it in, and put a gradient directly under the handle's own "1 of 30 posts" label. That is a legibility gamble in every theme I cannot see.

**Peaks are spread over their neighbours.** One post is a fraction of a pixel on a 400-post track. A band is something a reader can aim at, and "around here" is the honest claim anyway.

**Short threads get nothing.** Under twelve posts, configurable, there is no shape worth drawing and the reader can already see the whole scrubber.

## What it costs

One request per discussion per page load: four aggregate counts, grouped by post. It lives on a route of its own rather than as an attribute on the discussion resource — hanging it there would run those counts for every row of the discussion list, to draw nothing.

## Install

```
composer require ernestdefoe/ridge
```

- **GitHub:** https://github.com/ernestdefoe/ridge
- **Packagist:** https://packagist.org/packages/ernestdefoe/ridge
- **Support:** https://ernestdefoe.online/d/95
- **Licence:** MIT

Ideas welcome, particularly on the weights — what a signal is *worth* is the part where reasonable people will disagree.
