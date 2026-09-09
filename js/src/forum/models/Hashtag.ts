import Model from 'flarum/common/Model';

/**
 * Mirrors Ernestdefoe\Hashtags\Api\Resource\HashtagResource.
 *
 * `name` is the display casing (as first written by whoever used it first);
 * `nameKey` is the case-folded form the routes and filters match on. Anything
 * user-facing shows `name`, anything addressable uses `nameKey`.
 */
export default class Hashtag extends Model {
  name() {
    return Model.attribute<string>('name').call(this);
  }

  nameKey() {
    return Model.attribute<string>('nameKey').call(this);
  }

  postCount() {
    return Model.attribute<number>('postCount').call(this);
  }

  discussionCount() {
    return Model.attribute<number>('discussionCount').call(this);
  }

  lastUsedAt() {
    return Model.attribute<Date | null, string | null>('lastUsedAt', Model.transformDate).call(this);
  }
}
