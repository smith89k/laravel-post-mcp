<?php

namespace Smith89k\PostMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A post with its own column names, a custom key, an enum status and Markdown.
 */
class UnusualPost extends Model
{
    public const CREATED_AT = 'created_on';

    public const UPDATED_AT = 'modified_on';

    protected $table = 'articles';

    protected $primaryKey = 'article_id';

    protected $guarded = ['article_id', 'word_count'];

    protected function casts(): array
    {
        return [
            'state' => PostState::class,
            'goes_live_at' => 'datetime',
        ];
    }
}
