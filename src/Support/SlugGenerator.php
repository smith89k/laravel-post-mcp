<?php

namespace Smith89k\PostMcp\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Smith89k\PostMcp\PostMcp;

/**
 * Makes a unique slug from the title, steering clear of reserved slugs.
 */
class SlugGenerator
{
    public function __construct(protected PostMcp $postMcp) {}

    public function generate(string $title, ?Model $ignore = null): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'post';
        }

        $base = Str::limit($base, 240, '');
        $slug = $base;
        $suffix = 2;

        while ($this->isTaken($slug, $ignore)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return list<string>
     */
    public function reserved(): array
    {
        return array_values(array_map('strval', (array) $this->postMcp->config('slug.reserved', [])));
    }

    protected function isTaken(string $slug, ?Model $ignore): bool
    {
        if (in_array(strtolower($slug), array_map('strtolower', $this->reserved()), true)) {
            return true;
        }

        $column = $this->postMcp->slugColumn();

        if ($column === null) {
            return false;
        }

        return $this->postMcp->query()
            ->where($column, $slug)
            ->when($ignore?->exists, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
            ->exists();
    }
}
