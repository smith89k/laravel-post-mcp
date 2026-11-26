<?php

namespace Smith89k\PostMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A full blog post: author, category, tags, SEO fields, a featured image and scheduling.
 */
class RichPost extends Model
{
    /**
     * Counts saves, to prove model events run.
     */
    public static int $savingEvents = 0;

    protected $table = 'rich_posts';

    protected $fillable = [
        'title', 'slug', 'excerpt', 'body', 'seo_title', 'seo_description',
        'featured_image', 'status', 'published_at', 'category_id', 'api_token',
    ];

    protected $hidden = ['api_token'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn () => static::$savingEvents++);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'rich_post_tag');
    }
}
