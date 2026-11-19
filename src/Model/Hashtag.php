<?php

namespace Ernestdefoe\Hashtags\Model;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property string $name
 * @property string $name_key
 * @property \Carbon\Carbon|null $created_at
 *
 * Not columns: selected by VisibleUsage, counted over the posts the reader can see.
 * @property int $post_count
 * @property int $discussion_count
 * @property \Carbon\Carbon|null $last_used_at
 */
class Hashtag extends AbstractModel
{
    protected $table = 'hashtags';

    /**
     * created_at is set by the column default and there is no updated_at
     * column, so letting Eloquent manage timestamps would try to write one
     * that does not exist.
     */
    public $timestamps = false;

    protected $casts = [
        'post_count' => 'integer',
        'discussion_count' => 'integer',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected $fillable = ['name', 'name_key'];

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
}
