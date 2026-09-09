import app from 'flarum/forum/app';
import Page from 'flarum/forum/components/Page';
import DiscussionList from 'flarum/forum/components/DiscussionList';
import DiscussionListState from 'flarum/forum/states/DiscussionListState';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import type Mithril from 'mithril';
import type Hashtag from '../models/Hashtag';

/**
 * `/hashtag/:name` — every discussion containing a post that used this hashtag.
 *
 * The list is a stock DiscussionList driven by the `hashtag:` search filter, so
 * visibility scoping, pagination and the sort dropdown are core's rather than
 * ours. A hashtag used only in discussions the actor can't see renders the
 * same empty state as one nobody has used.
 */
export default class HashtagPage extends Page {
  /** Case-folded name from the route. */
  name!: string;

  /** The hashtag record, for the header counts. `null` once we know there is none. */
  hashtag: Hashtag | null = null;

  loading = true;

  list!: DiscussionListState;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);

    this.name = decodeURIComponent(m.route.param('name') || '');

    this.list = new DiscussionListState({ filter: { hashtag: this.name } });
    this.list.refresh();

    app.setTitle(`#${this.name}`);

    /**
     * The header record is a nicety, not a gate — the discussion list is
     * already loading in parallel off the name alone. A 404 here just means
     * nobody is using the hashtag right now, which is a normal state for
     * freeform hashtags and renders as the empty message rather than an error.
     */
    app.store
      .find<Hashtag>('hashtags', encodeURIComponent(this.name))
      .then((hashtag) => {
        this.hashtag = hashtag;
        app.setTitle(`#${hashtag.name()}`);
      })
      .catch(() => {
        this.hashtag = null;
      })
      .then(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    // Prefer the stored display casing once we have it, so #gameday and
    // #GameDay both land on a page headed the way it was first written.
    const display = this.hashtag ? this.hashtag.name() : this.name;

    return (
      <div className="HashtagPage">
        <div className="HashtagPage-header">
          <div className="container">
            <h1 className="HashtagPage-title">
              <span className="HashtagPage-hash">#</span>
              {display}
            </h1>

            {this.hashtag ? (
              <p className="HashtagPage-meta">
                {app.translator.trans('ernestdefoe-hashtags.forum.page.meta', {
                  posts: this.hashtag.postCount(),
                  discussions: this.hashtag.discussionCount(),
                })}
              </p>
            ) : null}

            <Link className="HashtagPage-browse" href={app.route('hashtags')}>
              {app.translator.trans('ernestdefoe-hashtags.forum.page.browse_all')}
            </Link>
          </div>
        </div>

        <div className="container">
          {this.loading && this.list.isLoading() ? (
            <LoadingIndicator />
          ) : this.list.isEmpty() ? (
            <p className="HashtagPage-empty">
              {app.translator.trans('ernestdefoe-hashtags.forum.page.empty', { name: display })}
            </p>
          ) : (
            <DiscussionList state={this.list} />
          )}
        </div>
      </div>
    );
  }
}
