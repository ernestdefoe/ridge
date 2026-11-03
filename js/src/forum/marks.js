import app from 'flarum/forum/app';

/**
 * A mark for every post on the scrubber, and the Lens: the posts around the
 * pointer as rows big enough to click.
 *
 * 🚨 Marks are spaced evenly, post i at i/count of the track. Core's own
 * handle is not — it is at least 50px tall, so the posts on screen get more
 * track than the rest, and that share moves as you read. Marks drawn the way
 * core lays out the handle would slide every time you scrolled.
 *
 * 🚨 Core binds `preventDefault` to touchstart on the whole track, which
 * swallows a tap's click, and a click anywhere on the track jumps the stream.
 * The marks layer stops both from reaching the track, so a tap opens the Lens
 * instead and a mouse click lands on exactly the post that is lit, not on
 * core's estimate of it. The handle sits above the layer and drags as before.
 */
const LENS_ROWS = 7;
const MIN_GAP = 3; // px between hairlines before they are thinned out

const cache = new Map();
const pending = new Map();

function labels(discussionId) {
  const id = String(discussionId);

  if (cache.has(id)) return Promise.resolve(cache.get(id));
  if (pending.has(id)) return pending.get(id);

  const promise = app
    .request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/ridge/${id}/posts` })
    .then((response) => {
      const { posts = [], users = {} } = response?.data?.attributes || {};
      const byId = new Map(posts.map((row) => [row[0], row]));

      cache.set(id, { byId, users });

      return cache.get(id);
    })
    .catch((e) => {
      // Rows fall back to "Post 12" rather than retrying on every hover.
      console.error('[ridge] could not load the post labels:', e);
      cache.set(id, { byId: new Map(), users: {} });

      return cache.get(id);
    })
    .finally(() => pending.delete(id));

  pending.set(id, promise);

  return promise;
}

export function markUp(element, stream) {
  const scrollbar = element.querySelector('.Scrubber-scrollbar');
  const count = stream?.count?.() || 0;

  if (!scrollbar) return;

  let layer = scrollbar.querySelector(':scope > .Ridge-marks');

  if (count < 2) {
    layer?.remove();

    return;
  }

  if (!layer) {
    layer = build(element, scrollbar, stream);
    scrollbar.appendChild(layer);

    // Core resizes the track on window resize without a redraw, so the
    // spacing follows the track's own size rather than waiting for a scroll.
    if (window.ResizeObserver) new ResizeObserver(() => fit(layer, scrollbar, stream)).observe(scrollbar);
  }

  fit(layer, scrollbar, stream);
}

function fit(layer, scrollbar, stream) {
  const count = stream.count();
  const height = scrollbar.clientHeight;

  // A closed phone dropdown has no height; the observer fires again on open.
  if (!height) return;

  // The scrubber redraws on every scroll frame: only write when something
  // changed.
  if (layer.dataset.count === String(count) && layer.dataset.height === String(height)) return;

  layer.dataset.count = String(count);
  layer.dataset.height = String(height);

  const stride = Math.max(1, Math.ceil(MIN_GAP / (height / count)));

  layer.style.setProperty('--ridge-cell', `${(100 / count) * stride}%`);
}

function build(element, scrollbar, stream) {
  const layer = document.createElement('div');
  layer.className = 'Ridge-marks';

  const hot = document.createElement('div');
  hot.className = 'Ridge-marks-hot';
  hot.hidden = true;
  layer.appendChild(hot);

  let lens = null;
  let hideTimer = null;
  let lastPointer = 'mouse';
  let hovered = -1;

  const discussion = stream.discussion;

  const indexAt = (clientY) => {
    const rect = layer.getBoundingClientRect();
    const count = stream.count();

    return Math.max(0, Math.min(count - 1, Math.floor(((clientY - rect.top) / rect.height) * count)));
  };

  const go = (index) => {
    closeLens();
    stream.goToIndex(index);
    // On phones the scrubber is a dropdown; core closes it the same way after
    // a jump.
    element.classList.remove('open');
  };

  const closeLens = () => {
    clearTimeout(hideTimer);
    lens?.remove();
    lens = null;
    hot.hidden = true;
    hovered = -1;
    scrollbar.classList.remove('Ridge-lensOpen');
  };

  const openLens = (index) => {
    clearTimeout(hideTimer);

    const count = stream.count();
    const per = 100 / count;

    hovered = index;
    hot.hidden = false;
    hot.style.top = `${index * per}%`;
    hot.style.height = `${per}%`;

    if (!lens) {
      lens = document.createElement('div');
      lens.className = 'Ridge-lens';
      lens.setAttribute('role', 'menu');
      lens.setAttribute('aria-label', app.translator.trans('ernestdefoe-ridge.forum.lens_label', {}, true));
      lens.addEventListener('pointerenter', () => clearTimeout(hideTimer));
      layer.appendChild(lens);
      scrollbar.classList.add('Ridge-lensOpen');
    }

    lens.style.top = `${(index + 0.5) * per}%`;

    const ids = discussion.postIds();
    const first = Math.max(0, Math.min(count - LENS_ROWS, index - Math.floor(LENS_ROWS / 2)));
    const last = Math.min(count - 1, first + LENS_ROWS - 1);

    labels(discussion.id()).then((data) => {
      if (!lens || hovered !== index) return;

      lens.replaceChildren(...range(first, last).map((i) => row(i, i === index, ids[i], data)));
    });
  };

  const row = (i, isHot, postId, data) => {
    const post = data.byId.get(Number(postId));
    const button = document.createElement('button');

    button.type = 'button';
    button.setAttribute('role', 'menuitem');
    button.className = 'Ridge-lens-row' + (isHot ? ' is-hot' : '') + (post?.[4] ? ' is-event' : '');

    const number = document.createElement('b');
    number.textContent = `#${post ? post[1] : i + 1}`;

    const who = document.createElement('span');
    who.textContent = post?.[4]
      ? app.translator.trans('ernestdefoe-ridge.forum.event_row', {}, true)
      : (post && data.users[post[2]]) || app.translator.trans('core.lib.username.deleted_text', {}, true);

    const when = document.createElement('em');
    when.textContent = post?.[3] ? shortDate(post[3]) : '';

    button.append(number, who, when);
    button.addEventListener('pointerenter', () => {
      lens.querySelectorAll('.is-hot').forEach((x) => x.classList.remove('is-hot'));
      button.classList.add('is-hot');
    });
    button.addEventListener('click', (e) => {
      e.stopPropagation();
      go(i);
    });

    return button;
  };

  // A dropdown closed with the Lens open must not reopen with it still there.
  $(element).on('hidden.bs.dropdown', closeLens);

  // Keep core's track handlers out of everything that happens on the layer.
  ['mousedown', 'touchstart'].forEach((type) => layer.addEventListener(type, (e) => e.stopPropagation()));

  layer.addEventListener('pointerdown', (e) => {
    lastPointer = e.pointerType;
  });

  layer.addEventListener('pointermove', (e) => {
    if (e.pointerType !== 'mouse' || lens?.contains(e.target)) return;

    const index = indexAt(e.clientY);

    if (index !== hovered) openLens(index);
  });

  layer.addEventListener('pointerleave', (e) => {
    // 🚨 Only a mouse leaving closes it. A finger lifting also fires
    // pointerleave, and the tap that opened the Lens would close it again.
    if (e.pointerType !== 'mouse') return;

    hideTimer = setTimeout(closeLens, 250);
  });

  layer.addEventListener('click', (e) => {
    e.stopPropagation();

    if (lens?.contains(e.target)) return;

    const index = indexAt(e.clientY);

    // A mouse already sees which post is lit, so a click goes there. A tap
    // has had no hover yet: it opens the Lens, and a row takes you there.
    if (lastPointer === 'mouse') go(index);
    else openLens(index);
  });

  return layer;
}

function range(from, to) {
  const out = [];

  for (let i = from; i <= to; i++) out.push(i);

  return out;
}

function shortDate(unix) {
  const date = dayjs.unix(unix);

  return date.format(date.year() === dayjs().year() ? 'MMM D' : 'll');
}
