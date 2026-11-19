import Extend from 'flarum/common/extenders';

import Hashtag from './models/Hashtag';

export default [
  new Extend.Store().add('hashtags', Hashtag),

  /**
   * The pages load on demand, as their own chunks (js/dist/forum, declared
   * with jsDirectory in extend.php): most visitors never open a hashtag feed,
   * so they don't download it. Route names matter: see extend.php.
   */
  new Extend.Routes()
    .add('hashtag', '/hashtag/:name', () => import('./components/HashtagPage'))
    .add('hashtags', '/hashtags', () => import('./components/HashtagsPage')),
];
