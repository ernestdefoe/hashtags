<?php

namespace Ernestdefoe\Hashtags\Model;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;

/**
 * @property int $id
 * @property string $name
 * @property string $name_key
 * @property int $post_count
 * @property int $discussion_count
 * @property \Carbon\Carbon|null $last_used_at
 * @property \Carbon\Carbon|null $created_at
 */
class Hashtag extends AbstractModel
{
    protected $table = 'hashtags';

    /**
     * created_at is set by the column default and last_used_at is written
     * explicitly by the syncer; there is no updated_at column, so letting
     * Eloquent manage timestamps would try to write one that does not exist.
     */
    public $timestamps = false;

    protected $casts = [
        'post_count' => 'integer',
        'discussion_count' => 'integer',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected $fillable = ['name', 'name_key', 'post_count', 'discussion_count', 'last_used_at'];

    /**
     * Case-fold a hashtag into its lookup key.
     *
     * mb_strtolower, not strtolower: the pattern matches \p{L}, so names can be
     * non-ASCII and the C-locale strtolower would leave `#CAFÉ` and `#café` as
     * two separate hashtags.
     */
    public static function key(string $name): string
    {
        return mb_strtolower(trim($name), 'UTF-8');
    }

    public function posts()
    {
        return $this->belongsToMany(Post::class, 'post_hashtag', 'hashtag_id', 'post_id');
    }

    public function discussions()
    {
        return $this->belongsToMany(Discussion::class, 'post_hashtag', 'hashtag_id', 'discussion_id');
    }
}
