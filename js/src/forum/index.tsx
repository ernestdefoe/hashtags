import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexPage from 'flarum/forum/components/IndexPage';
import LinkButton from 'flarum/common/components/LinkButton';

import Hashtag from './models/Hashtag';
import HashtagPage from './components/HashtagPage';
import HashtagsPage from './components/HashtagsPage';
import registerMentionable from './registerMentionable';

app.initializers.add('ernestdefoe-hashtags', () => {
  app.store.models.hashtags = Hashtag;

  app.routes.hashtag = { path: '/hashtag/:name', component: HashtagPage };
  app.routes.hashtags = { path: '/hashtags', component: HashtagsPage };

  /**
   * Composer autocomplete. Guarded rather than required: hashtags parse, link
   * and browse perfectly well without flarum/mentions — you just lose the
   * suggestions while typing. The import lives behind this check because
   * `ext:flarum/mentions/…` does not resolve when mentions isn't installed.
   */
  if ('flarum-mentions' in flarum.extensions) {
    registerMentionable();
  }

  /**
   * Discoverability: without an entry point, nobody finds /hashtags. Priority
   * -10 puts it below the stock nav items rather than above "All Discussions".
   */
  extend(IndexPage.prototype, 'navItems', function (items) {
    items.add(
      'hashtags',
      <LinkButton href={app.route('hashtags')} icon="fas fa-hashtag">
        {app.translator.trans('ernestdefoe-hashtags.forum.nav.browse')}
      </LinkButton>,
      -10
    );
  });
});
