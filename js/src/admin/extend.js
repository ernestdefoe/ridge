import app from 'flarum/admin/app';
import Admin from 'flarum/common/extenders/Admin';

/**
 * Ridge's admin settings.
 *
 * 🚨 An Admin extender, never `app.extensionData.for(...)` in an initializer.
 *
 * That was the Flarum 1 API. In Flarum 2 the property is GONE — not
 * deprecated, absent. It was renamed to `app.registry` and marked `@internal`,
 * so the extender is the supported way in.
 *
 * The old call failed quietly, which is why it survived a release: core wraps
 * every initializer in a try/catch, so the TypeError surfaced only as a toast
 * saying the extension "failed to initialize" and this setting simply never
 * appeared on the page. Everything else registered normally, so it read as a
 * missing feature rather than as a crash.
 *
 * 🚨 Exported from js/src/admin/index.js and NOWHERE else. This file imports
 * `flarum/admin/app`, which does not exist on the forum frontend —
 * re-exporting it from the forum entry runs it during forum boot and takes
 * every page of the forum down, not just the admin panel.
 */
export default [
  new Admin().setting(() => ({
    setting: 'ridge.min_posts',
    type: 'number',
    label: app.translator.trans('ernestdefoe-ridge.admin.min_posts_label'),
    help: app.translator.trans('ernestdefoe-ridge.admin.min_posts_help'),
    min: 2,
    placeholder: '12',
  })),
];
