<?php

namespace Smith89k\PostMcp\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Saves posts. Bind your own implementation to save through your site's services.
 */
interface PostWriter
{
    /**
     * @param  array<string, mixed>  $attributes  validated column values
     * @param  array<string, list<int|string>|int|string|null>  $relations  related keys by relation name
     */
    public function create(array $attributes, array $relations): Model;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, list<int|string>|int|string|null>  $relations
     */
    public function update(Model $post, array $attributes, array $relations): Model;

    public function delete(Model $post): void;
}
