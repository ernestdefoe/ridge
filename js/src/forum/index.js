import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';

import { heatFor, request } from './heat';
import { gradientFor, tintFrom } from './draw';
import { markUp } from './marks';

/*
 * 🚨 The extends name a module PATH. PostStreamScrubber is code-split — it
 * arrives in its own chunk when a discussion opens — so an import resolves to
 * a registry lookup that runs at boot, before the chunk exists, and
 * `extend(undefined.prototype, …)` does not fail quietly: it takes the whole
 * forum bundle down.
 */
app.initializers.add('ernestdefoe-ridge', () => {
  ['oncreate', 'onupdate'].forEach((hook) => {
    extend('flarum/forum/components/PostStreamScrubber', hook, function (_, vnode) {
      try {
        paint(vnode.dom, this.attrs?.stream?.discussion);
      } catch (e) {
        console.error('[ridge] leaving the scrubber alone:', e);
      }

      // 🚨 Settings are read here, not in the initializer: initializers run
      // before app.forum exists, and reading it there threw and stopped Ridge
      // from starting at all.
      //
      // A marker on <html>, not a class on the scrubber: Mithril owns the
      // scrubber's className and would strip one added by hand on its next
      // diff.
      document.documentElement.toggleAttribute('data-ridge-big-number', !!app.forum.attribute('ridgeBigNumber'));

      if (!app.forum.attribute('ridgePostMarks')) return;

      try {
        markUp(vnode.dom, this.attrs?.stream);
      } catch (e) {
        console.error('[ridge] leaving the post marks off:', e);
      }
    });
  });
});

function paint(element, discussion) {
  if (!element || !discussion) return;

  const heat = heatFor(discussion.id());

  if (!heat) {
    // The post ids are every post this reader can see, events included, so
    // fewer of them than the minimum means fewer visible comments too — the
    // server's answer is already known, and the request is skipped.
    const seen = discussion.postIds?.()?.length;

    if (seen && seen < (app.forum.attribute('ridgeMinPosts') || 12)) return;

    request(discussion.id());

    return;
  }

  /*
   * 🚨 `.Scrubber-scrollbar`, not `.PostStreamScrubber-scrollbar`.
   *
   * The component is called PostStreamScrubber but it renders a Dropdown whose
   * menu contains a `.Scrubber`, and the track inside that is `Scrubber-`
   * prefixed. Guessing the class from the component name found nothing, and
   * finding nothing is silent: the heat was fetched, the maths ran, and the
   * page simply looked unchanged.
   */
  const scrollbar = element.querySelector('.Scrubber-scrollbar');

  if (!scrollbar) return;

  /*
   * 🚨 Idempotent, and reused rather than rebuilt.
   *
   * The scrubber redraws on every scroll frame. Appending a fresh element each
   * time would stack hundreds of gradients inside the track within a second of
   * reading; rebuilding one element's background on every frame would re-parse
   * a sixty-stop gradient just as often. So the element is created once and
   * its background is only written when it actually changes.
   */
  let track = scrollbar.querySelector(':scope > .Ridge-track');

  const gradient = gradientFor(heat, tintFrom(element));

  if (!gradient) {
    track?.remove();

    return;
  }

  if (!track) {
    track = document.createElement('div');
    track.className = 'Ridge-track';
    track.setAttribute('aria-hidden', 'true');
    scrollbar.insertBefore(track, scrollbar.firstChild);
  }

  if (track.dataset.ridgeGradient !== gradient) {
    track.style.backgroundImage = gradient;
    track.dataset.ridgeGradient = gradient;
  }
}
