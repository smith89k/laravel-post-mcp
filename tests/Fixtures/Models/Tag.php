<?php

namespace Smith89k\PostMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'links' => 'array',
            'aliases' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (Tag $tag) => $tag->slug ??= Str::slug($tag->name));
    }
}
