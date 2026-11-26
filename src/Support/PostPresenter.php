<?php

namespace Smith89k\PostMcp\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Smith89k\PostMcp\Fields\Field;
use Smith89k\PostMcp\Fields\FieldMap;
use Smith89k\PostMcp\PostMcp;
use Smith89k\PostMcp\Status\StatusManager;
use UnitEnum;

/**
 * Turns a post into the array Claude reads. Only readable fields are included.
 */
class PostPresenter
{
    public function __construct(
        protected PostMcp $postMcp,
        protected StatusManager $status,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function full(Model $post, FieldMap $map): array
    {
        $data = ['id' => $post->getKey()];

        foreach ($map->readable() as $name => $field) {
            if ($field->isRelation()) {
                continue;
            }

            $data[$name] = $this->value($post->getAttribute($name));
        }

        foreach ($map->readableRelations() as $name => $field) {
            $data[$name] = $this->relationValue($post, $field);
        }

        return [...$data, ...$this->meta($post)];
    }

    /**
     * A short row for list_posts.
     *
     * @return array<string, mixed>
     */
    public function summary(Model $post, FieldMap $map): array
    {
        $data = ['id' => $post->getKey()];

        foreach (['title', 'slug', 'published_at'] as $role) {
            $field = $map->byRole($role);

            if ($field !== null && $field->readable) {
                $data[$field->name] = $this->value($post->getAttribute($field->name));
            }
        }

        foreach ($map->readableRelations() as $name => $field) {
            if ($post->relationLoaded($name)) {
                $data[$name] = $this->relationValue($post, $field);
            }
        }

        return [...$data, ...$this->meta($post)];
    }

    /**
     * @return array{status: string, url: string|null}
     */
    protected function meta(Model $post): array
    {
        return [
            'status' => $this->status->statusOf($post),
            'url' => $this->postMcp->publicUrl($post),
        ];
    }

    protected function relationValue(Model $post, Field $field): mixed
    {
        $label = (string) ($field->relation['label'] ?? 'id');
        $related = $post->getRelationValue($field->name);

        if ($related instanceof Collection) {
            return $related->map(fn (Model $model): mixed => $this->value($model->getAttribute($label)))->values()->all();
        }

        return $related instanceof Model ? $this->value($related->getAttribute($label)) : null;
    }

    protected function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_object($value) && method_exists($value, 'toArray') => $value->toArray(),
            is_string($value) && ! mb_check_encoding($value, 'UTF-8') => Str::limit(base64_encode($value), 100),
            default => $value,
        };
    }
}
