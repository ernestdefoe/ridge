import app from 'flarum/admin/app';

app.initializers.add('ernestdefoe-ridge', () => {
  app.extensionData.for('ernestdefoe-ridge').registerSetting({
    setting: 'ridge.min_posts',
    type: 'number',
    label: app.translator.trans('ernestdefoe-ridge.admin.min_posts_label'),
    help: app.translator.trans('ernestdefoe-ridge.admin.min_posts_help'),
    min: 2,
    placeholder: '12',
  });
});
