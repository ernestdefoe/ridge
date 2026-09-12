import app from 'flarum/forum/app';

/**
 * The heat for a discussion, fetched once.
 *
 * One request per discussion per page load, issued the first time a scrubber
 * asks. The scrubber redraws constantly — it follows the scroll — so anything
 * that asked per render would hammer the endpoint for a number that does not
 * change while you read.
 */
const cache = new Map();
const pending = new Set();

export function heatFor(discussionId) {
  return cache.get(String(discussionId));
}

export function request(discussionId) {
  const id = String(discussionId);

  if (cache.has(id) || pending.has(id)) return;

  pending.add(id);

  app
    .request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/ridge/${id}` })
    .then((response) => {
      cache.set(id, response?.data?.attributes || { enough: false, peaks: [] });
      m.redraw();
    })
    .catch((e) => {
      // Cached as empty rather than left pending: a forum where this fails
      // (an old install, a blocked route) should draw nothing quietly instead
      // of retrying on every scroll.
      cache.set(id, { enough: false, peaks: [] });
      console.error('[ridge] could not load the heat:', e);
    })
    .finally(() => pending.delete(id));
}
