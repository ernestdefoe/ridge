import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Rail, { YearChip } from './Rail';

/**
 * The year rail on the discussion list.
 *
 * 🚨 In forum.js, not a lazy chunk: measured, the rail is 2.0 KB gzipped,
 * while webpack's chunk-loading runtime alone added 1.4 KB to every page
 * and the chunk an extra round trip before the rail could draw.
 */
function wanted() {
  if (!app.forum.attribute('ridgeYears')) return false;

  const pages = app.forum.attribute('ridgeYearsPages');
  const onTag = app.current.get('routeName') === 'tag';

  return pages === 'both' || (pages === 'tags') === onTag;
}

export default function addYears() {
  extend('flarum/forum/components/IndexPage', 'contentItems', function (items) {
    if (!wanted()) return;

    // Between the toolbar (100) and the list (90): on a phone the strip sits
    // at the top of the list; on wider screens CSS moves it into a column of
    // its own beside the list.
    items.add('ridgeYears', <Rail />, 95);
    items.add('ridgeYearsChip', <YearChip />, 94);
  });
}
