import app from 'flarum/forum/app';
import HashtagMention from './mentionables/HashtagMention';

/**
 * Add hashtags to the composer's existing `#` autocomplete.
 *
 * Lives in its own module because it (transitively) imports from
 * `ext:flarum/mentions/…`, which only resolves when mentions is installed.
 * The caller guards on `'flarum-mentions' in flarum.extensions`, so on a forum
 * without mentions this module is never reached.
 */
export default function registerMentionable(): void {
  // `extendable` is true on core's HashMentionFormat, so this appends to the
  // same dropdown that already lists tags rather than replacing it. Optional
  // chaining because a future core could stop shipping a `#` format at all,
  // and a missing dropdown should not take the whole forum bundle down.
  app.mentionFormats.get('#')?.extend(HashtagMention);
}
