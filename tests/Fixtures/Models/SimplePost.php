<?php

namespace Smith89k\PostMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The smallest post: a title, a body and a status column.
 */
class SimplePost extends Model
{
    protected $table = 'simple_posts';

    protected $fillable = ['title', 'body', 'status'];
}
