<?php

namespace Smith89k\PostMcp\Writing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Smith89k\PostMcp\Contracts\PostWriter;
use Smith89k\PostMcp\PostMcp;

/**
 * Saves through Eloquent, so the model's events, observers and casts all run.
 */
class EloquentPostWriter implements PostWriter
{
    public function __construct(protected PostMcp $postMcp) {}

    public function create(array $attributes, array $relations): Model
    {
        return $this->save($this->postMcp->newModel(), $attributes, $relations);
    }

    public function update(Model $post, array $attributes, array $relations): Model
    {
        return $this->save($post, $attributes, $relations);
    }

    public function delete(Model $post): void
    {
        DB::connection($post->getConnectionName())->transaction(fn () => $post->delete());
    }

    /**
     * The attributes were validated against the field map, which only lets through
     * fillable columns and the ones the package manages, so forceFill is safe here.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, list<int|string>|int|string|null>  $relations
     */
    protected function save(Model $post, array $attributes, array $relations): Model
    {
        return DB::connection($post->getConnectionName())->transaction(function () use ($post, $attributes, $relations): Model {
            $post->forceFill($attributes);

            foreach ($relations as $name => $value) {
                $relation = $post->{$name}();

                if ($relation instanceof BelongsTo) {
                    $value === null ? $relation->dissociate() : $relation->associate(is_array($value) ? reset($value) : $value);
                }
            }

            $post->save();

            foreach ($relations as $name => $value) {
                $relation = $post->{$name}();

                if ($relation instanceof BelongsToMany) {
                    $relation->sync(is_array($value) ? $value : array_filter([$value]));
                }
            }

            return $post->refresh();
        });
    }
}
