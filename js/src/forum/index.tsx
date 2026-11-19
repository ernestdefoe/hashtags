import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';

export { default as extend } from './extend';

app.initializers.add('ernestdefoe-hashtags', () => {
  /**
   * Composer autocomplete. Guarded rather than required: hashtags parse, link
   * and browse perfectly well without flarum/mentions — you just lose the
   * suggestions while typing.
   *
   * 🚨 A dynamic import, never a static one. The module extends a class from
   * `ext:flarum/mentions/…`, and a static import evaluates that `extends`
   * when the bundle loads, before this check runs: without mentions it threw
   * "Class extends value undefined", and because core concatenates every
   * extension into one script, it stopped every extension after this one too.
   * As its own chunk it is only fetched and evaluated once mentions is known
   * to be there.
   */
  if ('flarum-mentions' in flarum.extensions) {
    // beforeMount, not straight away: the chunk loader reads app.forum for the
    // asset URL, and that isn't set while initializers run.
    app.beforeMount(() => {
      import('./registerMentionable').then(({ default: registerMentionable }) => registerMentionable());
    });
  }

  /**
   * Discoverability: without an entry point, nobody finds /hashtags.
   *
   * IndexSidebar, not IndexPage — Flarum 2 moved the nav list out of the page
   * component, and extending IndexPage here adds an item to a list nothing
   * renders. Priority -10 puts it below the stock items rather than above
   * "All Discussions".
   */
  extend(IndexSidebar.prototype, 'navItems', function (items) {
    items.add(
      'hashtags',
      <LinkButton href={app.route('hashtags')} icon="fas fa-hashtag">
        {app.translator.trans('ernestdefoe-hashtags.forum.nav.browse')}
      </LinkButton>,
      -10
    );
  });
});
