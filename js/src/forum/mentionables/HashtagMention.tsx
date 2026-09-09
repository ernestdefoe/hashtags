import app from 'flarum/forum/app';
import highlight from 'flarum/common/helpers/highlight';
import MentionableModel from 'ext:flarum/mentions/forum/mentionables/MentionableModel';
import type Mithril from 'mithril';
import type Hashtag from '../models/Hashtag';

/**
 * Offers existing hashtags in the composer's `#` dropdown, alongside
 * flarum/tags' tag suggestions.
 *
 * This is the piece that makes freeform hashtags cohere instead of fragment:
 * without it, #gameday, #GameDay and #game-day become three separate feeds
 * within a week because nobody can see what already exists while they type.
 *
 * Registered onto the EXISTING HashMentionFormat rather than a new format —
 * core declares that format `extendable`, so `#` keeps one dropdown showing
 * tags and hashtags together instead of two competing triggers.
 */
export default class HashtagMention extends MentionableModel<Hashtag> {
  type(): string {
    return 'hashtag';
  }

  /**
   * Nothing is offered before the user types. The store holds whatever
   * hashtags previous pages happened to load, which is an arbitrary set — a
   * dropdown of arbitrary hashtags on a bare `#` is noise, and worse, it would
   * push the tag suggestions (which ARE meaningful on a bare `#`) out of view.
   */
  initialResults(): Hashtag[] {
    return [];
  }

  async search(typed: string): Promise<Hashtag[]> {
    return await app.store.find<Hashtag[]>('hashtags', {
      filter: { q: typed },
      page: { limit: 5 },
      sort: '-postCount',
    });
  }

  /**
   * The dropdown filters the accumulated result set client-side as more is
   * typed. Prefix match on the folded key, mirroring the server's LIKE 'x%'.
   */
  matches(model: Hashtag, typed: string): boolean {
    if (!typed) return false;

    return model.nameKey().substr(0, typed.length) === typed.toLowerCase();
  }

  /**
   * Insert what the hashtag is actually called, not what was typed — picking
   * "#GameDay" from the list should write "#GameDay".
   */
  replacement(model: Hashtag): string {
    return this.format.format(model.name());
  }

  /**
   * null = don't cap store-matched results. The set only ever contains what
   * our own search() returned (initialResults is empty), which is already
   * limited to 5 server-side.
   */
  maxStoreMatchedResults(): null {
    return null;
  }

  suggestion(model: Hashtag, typed: string): Mithril.Children {
    return (
      <>
        <span className="HashtagMention-icon" aria-hidden="true">
          #
        </span>
        <span className="username">{typed ? highlight(model.name(), typed) : model.name()}</span>
        <span className="HashtagMention-count">
          {app.translator.trans('ernestdefoe-hashtags.forum.composer.post_count', { count: model.postCount() })}
        </span>
      </>
    );
  }

  enabled(): boolean {
    return true;
  }
}
