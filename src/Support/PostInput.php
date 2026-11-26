<?php

namespace Smith89k\PostMcp\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Smith89k\PostMcp\Contracts\ContentConverter;
use Smith89k\PostMcp\Fields\Field;
use Smith89k\PostMcp\Fields\FieldMap;
use Smith89k\PostMcp\PostMcp;

/**
 * Validates what Claude sends for a create or update and turns it into column
 * values and related keys.
 */
class PostInput
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public function __construct(
        protected PostMcp $postMcp,
        protected FormRequestRules $formRequestRules,
        protected SlugGenerator $slugs,
        protected RelationResolver $relations,
        protected Container $container,
    ) {}

    /**
     * @param  array<string, mixed>  $input  only the post fields, without tool options
     * @return array{attributes: array<string, mixed>, relations: array<string, list<int|string>|int|string|null>}
     *
     * @throws ValidationException
     */
    public function prepare(FieldMap $map, string $operation, array $input, ?Model $post = null): array
    {
        $columns = $map->writableColumns();
        $relationFields = $map->writableRelations();

        $this->rejectUnknown($map, $input, [...array_keys($columns), ...array_keys($relationFields)]);

        $rules = $this->rules($columns, $relationFields, $operation, $post);
        [$rules, $messages, $attributeNames] = $this->mergeFormRequest($rules, $columns, $operation, $input, $post);

        $validated = Validator::make($input, $rules, $messages, $attributeNames)->validate();

        $attributes = array_intersect_key($validated, $columns);
        $attributes = $this->convertContent($attributes);
        $attributes = $this->fillSlug($attributes, $operation, $post);

        $relations = [];

        foreach ($relationFields as $name => $field) {
            if (array_key_exists($name, $input)) {
                $relations[$name] = $this->relations->resolve($field, $input[$name]);
            }
        }

        return ['attributes' => $attributes, 'relations' => $relations];
    }

    /**
     * @param  array<string, Field>  $columns
     * @param  array<string, Field>  $relationFields
     * @return array<string, list<mixed>>
     */
    public function rules(array $columns, array $relationFields, string $operation, ?Model $post = null): array
    {
        $rules = [];

        foreach ($columns as $name => $field) {
            $fieldRules = $operation === self::UPDATE ? ['sometimes'] : [];
            $fieldRules[] = $this->isRequired($field, $operation) ? 'required' : 'nullable';

            $typeRule = match ($field->type) {
                Field::TYPE_STRING, Field::TYPE_TEXT => 'string',
                Field::TYPE_INTEGER => 'integer',
                Field::TYPE_NUMBER => 'numeric',
                Field::TYPE_BOOLEAN => 'boolean',
                Field::TYPE_DATE, Field::TYPE_DATETIME => 'date',
                Field::TYPE_ARRAY, Field::TYPE_OBJECT => 'array',
                default => null,
            };

            if ($typeRule !== null) {
                $fieldRules[] = $typeRule;
            }

            if ($field->maxLength !== null && in_array($field->type, [Field::TYPE_STRING, Field::TYPE_TEXT], true)) {
                $fieldRules[] = 'max:'.$field->maxLength;
            }

            if ($field->enum !== null && $field->enum !== []) {
                $fieldRules[] = Rule::in($field->enum);
            }

            if ($field->role === 'slug') {
                $fieldRules = [...$fieldRules, ...$this->slugRules($post)];
            }

            $rules[$name] = [...$fieldRules, ...$field->rules];
        }

        foreach ($relationFields as $name => $field) {
            $rules[$name] = $field->isMultipleRelation()
                ? ($operation === self::UPDATE ? ['sometimes', 'nullable', 'array'] : ['nullable', 'array'])
                : ($operation === self::UPDATE ? ['sometimes', 'nullable'] : ['nullable']);
        }

        return $rules;
    }

    protected function isRequired(Field $field, string $operation): bool
    {
        if (! $field->required) {
            return false;
        }

        return ! ($field->role === 'slug' && in_array($this->postMcp->slugStrategy(), ['from_title', 'model'], true) && $operation === self::CREATE);
    }

    /**
     * @return list<mixed>
     */
    protected function slugRules(?Model $post): array
    {
        $column = (string) $this->postMcp->slugColumn();
        $model = $this->postMcp->newModel();
        $unique = Rule::unique($model->getConnectionName() ? $model->getConnectionName().'.'.$model->getTable() : $model->getTable(), $column);

        if ($post?->exists) {
            $unique->ignore($post->getKey(), $post->getKeyName());
        }

        $rules = ['alpha_dash', $unique];

        if ($this->slugs->reserved() !== []) {
            $rules[] = Rule::notIn($this->slugs->reserved());
        }

        return $rules;
    }

    /**
     * Puts the site's own FormRequest rules over the package's rules for the same columns.
     *
     * @param  array<string, list<mixed>>  $rules
     * @param  array<string, Field>  $columns
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>}
     */
    protected function mergeFormRequest(array $rules, array $columns, string $operation, array $input, ?Model $post): array
    {
        $class = $this->postMcp->config("validation.{$operation}");

        if (! is_string($class) || $class === '') {
            return [$rules, [], []];
        }

        foreach ($this->formRequestRules->rules($class, $input, $post) as $attribute => $siteRules) {
            $root = explode('.', (string) $attribute)[0];

            if (! isset($columns[$root])) {
                continue;
            }

            $siteRules = $this->formRequestRules->normalize($siteRules);

            if ($operation === self::UPDATE && $attribute === $root && ! in_array('sometimes', $siteRules, true)) {
                array_unshift($siteRules, 'sometimes');
            }

            $rules[$attribute] = $siteRules;
        }

        $extra = $this->formRequestRules->messages($class);

        return [$rules, $extra['messages'], $extra['attributes']];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowed
     */
    protected function rejectUnknown(FieldMap $map, array $input, array $allowed): void
    {
        $errors = [];

        foreach (array_keys($input) as $key) {
            if (in_array($key, $allowed, true)) {
                continue;
            }

            $field = $map->get((string) $key);
            $errors[$key] = $field !== null && $field->readable
                ? "{$key} cannot be changed here.".($field->notes ? ' '.$field->notes : '')
                : "{$key} is not a field of this post. Writable fields: ".implode(', ', $allowed).'.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function convertContent(array $attributes): array
    {
        $column = $this->postMcp->contentColumn();
        $converterClass = $this->postMcp->config('content.converter');

        if ($column === null || ! array_key_exists($column, $attributes) || ! is_string($converterClass) || $converterClass === '') {
            return $attributes;
        }

        $converter = $this->container->make($converterClass);

        if ($converter instanceof ContentConverter) {
            $attributes[$column] = $converter->convert(
                $attributes[$column],
                $this->postMcp->contentInputFormat(),
                $this->postMcp->contentFormat(),
            );
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function fillSlug(array $attributes, string $operation, ?Model $post): array
    {
        $column = $this->postMcp->slugColumn();

        if ($column === null || $operation !== self::CREATE || $this->postMcp->slugStrategy() !== 'from_title' || filled($attributes[$column] ?? null)) {
            return $attributes;
        }

        $source = (string) $this->postMcp->config('slug.source', $this->postMcp->titleColumn());
        $attributes[$column] = $this->slugs->generate((string) ($attributes[$source] ?? ''), $post);

        return $attributes;
    }
}
