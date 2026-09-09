import Extend from 'flarum/common/extenders';

import HashtagsPage from './components/HashtagsPage';

export default [
  /**
   * `Extend.Admin().page()`, NOT `app.extensionData.for(id).registerPage()`.
   *
   * `app.extensionData` is a Flarum 1.x API. It does not exist on Flarum 2's
   * AdminApplication — the registry is `app.registry` now — so calling `.for()`
   * on it threw "Cannot read properties of undefined", and because that ran
   * inside our initializer it took the WHOLE admin bundle for this extension
   * down, not just the settings page.
   */
  new Extend.Admin().page(HashtagsPage),
];
