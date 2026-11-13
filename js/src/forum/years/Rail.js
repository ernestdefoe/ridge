import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import abbreviateNumber from 'flarum/common/utils/abbreviateNumber';
import setRouteWithForcedRefresh from 'flarum/common/utils/setRouteWithForcedRefresh';

/**
 * The year rail: every year the list holds, newest first, each with its count
 * and a bar shaded by how much was said that year.
 *
 * 🚨 A year is a FILTER on the list (`?filter[ridgeYear]=2015`), not a jump
 * to the page where that year starts.
 *
 * Jumping would need the list to load upwards as well as down, which Flarum's
 * list does not do; the page a year starts on moves with the sort and with
 * every new discussion, so a shared link would land somewhere else tomorrow;
 * and under the default "Latest" sort a year's discussions are scattered
 * through the whole list by their last reply. As a filter, the list keeps its
 * own infinite scroll and sort inside the year, the URL says exactly what it
 * shows, and the filter rides along through Flarum's own sticky `filter`
 * parameter, so changing the sort or the tag keeps the year.
 */
const PREVIEW_SIZE = 5;
const HIDE_DELAY = 180;

const counts = new Map();
const pending = new Set();
const previews = new Map();

/** The list's own filter (tag, search, following…) without the year. */
function listFilter() {
  const filter = { ...(app.discussions.requestParams().filter || {}) };

  delete filter.ridgeYear;

  return filter;
}

function selectedYear() {
  return Number(app.search.state.params().filter?.ridgeYear) || null;
}

function routeFor(year) {
  const params = app.search.state.params();
  const filter = { ...(params.filter || {}) };

  if (year) filter.ridgeYear = String(year);
  else delete filter.ridgeYear;

  if (Object.keys(filter).length) params.filter = filter;
  else delete params.filter;

  return app.route(app.current.get('routeName'), params);
}

function go(event, year) {
  // Let a new tab or window open the link as it is.
  if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;

  event.preventDefault();
  setRouteWithForcedRefresh(routeFor(year));
}

function request(key, filter) {
  if (counts.has(key) || pending.has(key)) return;

  pending.add(key);

  app
    .request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/ridge-years`, params: { filter } })
    .then((response) => counts.set(key, response?.data?.attributes?.years || []))
    .catch((e) => {
      // Remembered as empty: a blocked route draws nothing rather than
      // asking again on every redraw.
      counts.set(key, []);
      console.error('[ridge] could not load the years:', e);
    })
    .finally(() => {
      pending.delete(key);
      m.redraw();
    });
}

function preview(key, filter, year) {
  const id = `${key}|${year}`;

  if (previews.has(id)) return previews.get(id);

  previews.set(id, null);

  // Core's own list endpoint, so a preview can never show a discussion the
  // list itself would not: same filters, same visibility, same permissions.
  app
    .request({
      method: 'GET',
      url: `${app.forum.attribute('apiUrl')}/discussions`,
      params: {
        filter: { ...filter, ridgeYear: String(year) },
        sort: '-commentCount',
        page: { limit: PREVIEW_SIZE },
        fields: { discussions: 'title,slug,commentCount' },
      },
    })
    .then((response) =>
      previews.set(
        id,
        (response?.data || []).map(({ id, attributes }) => ({ id, ...attributes }))
      )
    )
    .catch(() => previews.set(id, []))
    .finally(() => m.redraw());

  return null;
}

export default class Rail extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.hovered = null;
    this.hoverTop = 0;
    this.hideTimer = null;
  }

  view() {
    const filter = listFilter();
    const key = JSON.stringify(filter);
    const years = counts.get(key);

    this.key = key;
    this.filter = filter;

    if (!years) {
      request(key, filter);

      return <div className="RidgeYears RidgeYears--empty" />;
    }

    const selected = selectedYear();

    if (years.length < (app.forum.attribute('ridgeYearsMin') || 2)) {
      return <div className="RidgeYears RidgeYears--empty" />;
    }

    const busiest = Math.max(1, ...years.map((row) => row[2]));

    return (
      <div className="RidgeYears">
        <div className="RidgeYears-anchor" aria-hidden="true" />
        <nav className="RidgeYears-rail" aria-label={app.translator.trans('ernestdefoe-ridge.forum.years.label', {}, true)}>
          <ol className="RidgeYears-list" onscroll={() => this.hide(0)}>
            {years.map(([year, discussions, comments]) => {
              const share = comments / busiest;

              return (
                <li key={year}>
                  <a
                    className={'RidgeYears-year' + (year === selected ? ' is-active' : '')}
                    href={routeFor(year === selected ? null : year)}
                    aria-current={year === selected ? 'true' : undefined}
                    onclick={(e) => go(e, year === selected ? null : year)}
                    onmouseenter={(e) => this.show(e.currentTarget, year)}
                    onmouseleave={() => this.hide()}
                    onfocus={(e) => this.show(e.currentTarget, year)}
                    onblur={() => this.hide()}
                  >
                    <span className="RidgeYears-label">{year}</span>
                    <span
                      className="RidgeYears-count"
                      title={app.translator.trans('ernestdefoe-ridge.forum.years.count', { count: discussions }, true)}
                    >
                      {abbreviateNumber(discussions)}
                    </span>
                    <span className="RidgeYears-bar" aria-hidden="true">
                      <span style={{ width: `${Math.max(6, share * 100).toFixed(1)}%`, opacity: (0.3 + share * 0.7).toFixed(2) }} />
                    </span>
                  </a>
                </li>
              );
            })}
          </ol>
          {this.hovered ? this.previewCard() : null}
        </nav>
      </div>
    );
  }

  previewCard() {
    const year = this.hovered;
    const rows = preview(this.key, this.filter, year);

    return (
      <div
        className="RidgeYears-preview"
        style={{ top: `${this.hoverTop}px` }}
        onmouseenter={() => clearTimeout(this.hideTimer)}
        onmouseleave={() => this.hide()}
      >
        <div className="RidgeYears-previewTitle">{app.translator.trans('ernestdefoe-ridge.forum.years.preview_heading', { year })}</div>
        {rows === null ? (
          <div className="RidgeYears-previewNote">…</div>
        ) : rows.length ? (
          <ol>
            {rows.map((row) => (
              <li key={row.id}>
                <Link href={app.route('discussion', { id: row.slug || row.id })}>
                  <span className="RidgeYears-previewName">{row.title}</span>
                  <span className="RidgeYears-previewCount">{abbreviateNumber(Math.max(0, (row.commentCount || 1) - 1))}</span>
                </Link>
              </li>
            ))}
          </ol>
        ) : (
          <div className="RidgeYears-previewNote">{app.translator.trans('ernestdefoe-ridge.forum.years.preview_empty')}</div>
        )}
      </div>
    );
  }

  show(element, year) {
    // Hover only: a phone's strip has no pointer to rest on, and a tap there
    // means "take me to that year".
    if (!this.element || window.matchMedia('(max-width: 767.98px)').matches) return;

    clearTimeout(this.hideTimer);

    const rail = this.element.querySelector('.RidgeYears-rail');

    this.hovered = year;
    this.hoverTop = element.getBoundingClientRect().top - rail.getBoundingClientRect().top;
  }

  hide(delay = HIDE_DELAY) {
    clearTimeout(this.hideTimer);

    this.hideTimer = setTimeout(() => {
      if (this.hovered === null) return;

      this.hovered = null;
      m.redraw();
    }, delay);
  }

  oncreate(vnode) {
    super.oncreate(vnode);

    this.place = this.place.bind(this);
    this.pin();
    window.addEventListener('resize', this.place);

    // On a phone the picked year may be far along the strip: bring it into
    // view, sideways only (scrollIntoView would move the page as well).
    const list = this.element.querySelector('.RidgeYears-list');
    const active = list?.querySelector('.is-active');

    if (active && this.phone()) {
      list.scrollLeft = active.parentElement.offsetLeft - (list.clientWidth - active.offsetWidth) / 2;
    }
  }

  onupdate(vnode) {
    super.onupdate(vnode);

    this.pin();
  }

  onremove(vnode) {
    super.onremove(vnode);

    clearTimeout(this.hideTimer);
    this.observer?.disconnect();
    window.removeEventListener('resize', this.place);
    this.content?.style.removeProperty('min-height');
  }

  /**
   * Keeps the rail in view while the list scrolls under it.
   *
   * 🚨 `position: sticky` never sticks here: Flarum's page and most themes
   * clip overflow on an ancestor. So the rail's column holds its place, and
   * once its top would pass under the header the rail itself is fixed at the
   * column's left edge until the reader scrolls back.
   */
  pin() {
    const rail = this.element.querySelector('.RidgeYears-rail');

    // The rail is out of the list's flow on wide screens, so the content
    // area is held at least as tall as the rail — a year with three
    // discussions must not let the footer slide up under it.
    this.content = this.element.parentElement;

    if (this.content) {
      this.content.style.minHeight = rail && !this.phone() ? `${rail.offsetHeight + (rail.offsetTop || 0)}px` : '';
    }

    if (!rail || this.observedRail === rail) return;

    this.observer?.disconnect();
    this.observedRail = rail;

    const top = () => Math.max(0, document.getElementById('header')?.getBoundingClientRect().bottom || 0) + 16;

    this.observer = new IntersectionObserver(
      ([entry]) => {
        const pinned = !this.phone() && !entry.isIntersecting && entry.boundingClientRect.top < top();

        rail.classList.toggle('is-pinned', pinned);
        rail.style.top = pinned ? `${top()}px` : '';
        this.place();
      },
      { rootMargin: `-${top()}px 0px 0px 0px`, threshold: 0 }
    );

    this.observer.observe(this.element.querySelector('.RidgeYears-anchor'));
  }

  place() {
    const rail = this.element?.querySelector('.RidgeYears-rail');

    if (!rail) return;

    if (!rail.classList.contains('is-pinned')) {
      rail.style.left = rail.style.width = '';

      return;
    }

    const box = this.element.getBoundingClientRect();

    rail.style.left = `${box.left}px`;
    rail.style.width = `${box.width}px`;
  }

  phone() {
    return window.matchMedia('(max-width: 767.98px)').matches;
  }
}

/**
 * "Showing 2015 · 342 discussions — Back to latest", above the list while a
 * year is picked. Its own item so it sits in the list's column on every
 * screen, not in the rail's.
 */
export class YearChip extends Component {
  view() {
    const year = selectedYear();

    if (!year) return <div className="RidgeYears-chip RidgeYears-chip--none" hidden />;

    const row = (counts.get(JSON.stringify(listFilter())) || []).find(([y]) => y === year);

    return (
      <div className="RidgeYears-chip" role="status">
        <span className="RidgeYears-chipText">
          {app.translator.trans('ernestdefoe-ridge.forum.years.showing', { year: <strong>{year}</strong> })}
          {row ? (
            <span className="RidgeYears-chipCount">{app.translator.trans('ernestdefoe-ridge.forum.years.count', { count: row[1] })}</span>
          ) : null}
        </span>
        <a className="RidgeYears-back" href={routeFor(null)} onclick={(e) => go(e, null)}>
          <Icon name="fas fa-arrow-up" />
          {app.translator.trans('ernestdefoe-ridge.forum.years.back')}
        </a>
      </div>
    );
  }
}
