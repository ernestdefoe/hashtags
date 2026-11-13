import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Form from 'flarum/common/components/Form';

/**
 * Hashtags themselves deliberately have no settings.
 *
 * They are freeform: they exist because a post says so, and stop existing
 * when the last post using them changes. Anything configurable about them —
 * renaming one, deleting one, capping their length after the fact — would
 * immediately disagree with the post text that produced it.
 *
 * So the only setting is about the browse page, and what an admin otherwise
 * needs is the backfill, because installing this on an established forum
 * indexes nothing until posts are re-saved.
 */
export default class HashtagsPage extends ExtensionPage {
  content() {
    return (
      <div className="ExtensionPage-settings">
        <div className="container">
          <Form>
            {this.buildSettingComponent({
              setting: 'ernestdefoe-hashtags.per_page',
              type: 'number',
              min: 1,
              max: 100,
              label: app.translator.trans('ernestdefoe-hashtags.admin.settings.per_page_label'),
              help: app.translator.trans('ernestdefoe-hashtags.admin.settings.per_page_help'),
            })}
            <div className="Form-group Form-controls">{this.submitButton()}</div>
          </Form>

          <h2>{app.translator.trans('ernestdefoe-hashtags.admin.backfill.heading')}</h2>
          <p>{app.translator.trans('ernestdefoe-hashtags.admin.backfill.body')}</p>
          <pre className="HashtagsAdmin-command">php flarum hashtags:reindex</pre>
          <p className="helpText">{app.translator.trans('ernestdefoe-hashtags.admin.backfill.help')}</p>
        </div>
      </div>
    );
  }
}
