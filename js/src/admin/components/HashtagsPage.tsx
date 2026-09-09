import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';

/**
 * The admin panel deliberately has no settings.
 *
 * Hashtags are freeform: they exist because a post says so, and stop existing
 * when the last post using them changes. Anything configurable here — renaming
 * one, deleting one, capping their length after the fact — would immediately
 * disagree with the post text that produced it.
 *
 * What an admin genuinely needs is the backfill, because installing this on an
 * established forum indexes nothing until posts are re-saved.
 */
export default class HashtagsPage extends ExtensionPage {
  content() {
    return (
      <div className="ExtensionPage-settings">
        <div className="container">
          <h2>{app.translator.trans('ernestdefoe-hashtags.admin.backfill.heading')}</h2>
          <p>{app.translator.trans('ernestdefoe-hashtags.admin.backfill.body')}</p>
          <pre className="HashtagsAdmin-command">php flarum hashtags:reindex</pre>
          <p className="helpText">{app.translator.trans('ernestdefoe-hashtags.admin.backfill.help')}</p>
        </div>
      </div>
    );
  }
}
