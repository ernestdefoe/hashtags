import app from 'flarum/forum/app';
import Page from 'flarum/forum/components/Page';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import type Hashtag from '../models/Hashtag';

/** Sort key → the API sort alias declared in HashtagResource::sorts(). */
const SORTS: Record<string, string> = {
  popular: '-postCount',
  recent: '-lastUsedAt',
  alphabetical: 'name',
};

/**
 * `/hashtags` — browse what people are actually using.
 *
 * Freeform hashtags only pay off if they converge, and convergence needs two
 * things: autocomplete while writing (see HashtagMention) and a way to see
 * what already exists. This is the second one.
 */
export default class HashtagsPage extends Page {
  hashtags: Hashtag[] = [];
  loading = true;
  sort = 'popular';
  query = '';

  /** Guards against an earlier slow response overwriting a later fast one. */
  private requestId = 0;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);

    app.setTitle(extractText(app.translator.trans('ernestdefoe-hashtags.forum.browse.title')));

    this.load();
  }

  load() {
    const id = ++this.requestId;

    this.loading = true;

    const params: Record<string, any> = {
      sort: SORTS[this.sort],
      page: { limit: 60 },
    };

    if (this.query) params.filter = { q: this.query };

    app.store
      .find<Hashtag[]>('hashtags', params)
      .then((hashtags) => {
        // A stale response — the user has typed or re-sorted since. Dropping it
        // is what stops the list flickering back to previous results.
        if (id !== this.requestId) return;

        this.hashtags = hashtags;
      })
      .catch(() => {
        if (id !== this.requestId) return;

        this.hashtags = [];
      })
      .then(() => {
        if (id !== this.requestId) return;

        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <div className="HashtagsPage">
        <div className="HashtagsPage-header">
          <div className="container">
            <h1 className="HashtagsPage-title">
              {app.translator.trans('ernestdefoe-hashtags.forum.browse.title')}
            </h1>
            <p className="HashtagsPage-lede">
              {app.translator.trans('ernestdefoe-hashtags.forum.browse.lede')}
            </p>
          </div>
        </div>

        <div className="container">
          <div className="HashtagsPage-controls">
            <input
              className="FormControl HashtagsPage-search"
              type="search"
              placeholder={extractText(app.translator.trans('ernestdefoe-hashtags.forum.browse.search_placeholder'))}
              value={this.query}
              oninput={(e: InputEvent) => {
                this.query = (e.target as HTMLInputElement).value.trim();
                this.load();
              }}
            />

            <div className="HashtagsPage-sorts">
              {Object.keys(SORTS).map((key) => (
                <Button
                  className={'Button Button--link' + (this.sort === key ? ' active' : '')}
                  onclick={() => {
                    if (this.sort === key) return;
                    this.sort = key;
                    this.load();
                  }}
                >
                  {app.translator.trans(`ernestdefoe-hashtags.forum.browse.sort_${key}`)}
                </Button>
              ))}
            </div>
          </div>

          {this.loading ? (
            <LoadingIndicator />
          ) : this.hashtags.length === 0 ? (
            <p className="HashtagsPage-empty">
              {app.translator.trans(
                this.query ? 'ernestdefoe-hashtags.forum.browse.no_matches' : 'ernestdefoe-hashtags.forum.browse.empty'
              )}
            </p>
          ) : (
            <ul className="HashtagsPage-list">
              {this.hashtags.map((hashtag) => (
                <li className="HashtagsPage-item">
                  <Link className="HashtagChip" href={app.route('hashtag', { name: hashtag.nameKey() })}>
                    <span className="HashtagChip-name">#{hashtag.name()}</span>
                    <span className="HashtagChip-count">{hashtag.postCount()}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    );
  }
}
