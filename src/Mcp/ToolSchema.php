<?php

namespace Smith89k\PostMcp\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Smith89k\PostMcp\Fields\Field;
use Smith89k\PostMcp\Fields\FieldMap;
use Smith89k\PostMcp\PostMcp;
use Smith89k\PostMcp\Support\PostInput;

/**
 * Builds tool input schemas from the field map, so Claude sees the site's real fields.
 */
class ToolSchema
{
    public function __construct(protected PostMcp $postMcp) {}

    /**
     * @return array<string, Type>
     */
    public function postFields(JsonSchema $schema, FieldMap $map, string $operation): array
    {
        $properties = [];

        foreach ([...$map->writableColumns(), ...$map->writableRelations()] as $name => $field) {
            $type = $this->typeFor($schema, $field)->description($this->describe($field));

            if ($operation === PostInput::CREATE && $this->requiredOnCreate($field)) {
                $type->required();
            }

            $properties[$name] = $type;
        }

        return $properties;
    }

    public function id(JsonSchema $schema, string $description = 'The post id.'): Type
    {
        $type = $this->postMcp->newModel()->getKeyType() === 'int' ? $schema->integer() : $schema->string();

        return $type->description($description);
    }

    protected function typeFor(JsonSchema $schema, Field $field): Type
    {
        if ($field->isRelation()) {
            $item = $this->relationItem($schema, $field);

            return $field->isMultipleRelation() ? $schema->array()->items($item) : $item;
        }

        $type = match ($field->type) {
            Field::TYPE_INTEGER => $schema->integer(),
            Field::TYPE_NUMBER => $schema->number(),
            Field::TYPE_BOOLEAN => $schema->boolean(),
            Field::TYPE_DATETIME => $schema->string()->format('date-time'),
            Field::TYPE_DATE => $schema->string()->format('date'),
            Field::TYPE_ARRAY => $field->role === 'content' ? $schema->array()->items($schema->object()) : $schema->array(),
            Field::TYPE_OBJECT => $schema->object(),
            default => $schema->string(),
        };

        if ($field->maxLength !== null && method_exists($type, 'max') && in_array($field->type, [Field::TYPE_STRING, Field::TYPE_TEXT], true)) {
            $type->max($field->maxLength);
        }

        if ($field->enum !== null && $field->enum !== []) {
            $type->enum($field->enum);
        }

        return $type;
    }

    /**
     * A related item: its label, or an object with the label and the details Claude may fill in.
     */
    protected function relationItem(JsonSchema $schema, Field $field): Type
    {
        $details = $field->relationDetails();

        if ($details === []) {
            return $schema->string();
        }

        $label = (string) ($field->relation['label'] ?? 'id');
        $properties = [$label => $schema->string()->description('The '.$label.'.')->required()];

        foreach ($details as $name => $detail) {
            $properties[$name] = $this->detailType($schema, $detail)->description($detail['description']);
        }

        $object = $schema->object($properties);

        // anyOf arrived in Laravel 13; on older versions Claude sends every item as an object,
        // and a plain label is still accepted when it does not.
        // @phpstan-ignore function.alreadyNarrowedType (always true on the Laravel 13 used for analysis, false on Laravel 12)
        return method_exists($schema, 'anyOf') ? $schema->anyOf([$schema->string(), $object]) : $object;
    }

    /**
     * @param  array{type: string, description: string, rules: list<mixed>, each: array<string, list<mixed>>, image: array{disk: string, directory: string}|null, grow: bool}  $detail
     */
    protected function detailType(JsonSchema $schema, array $detail): Type
    {
        if ($detail['each'] !== []) {
            $parts = [];

            foreach ($detail['each'] as $part => $rules) {
                $parts[$part] = in_array('required', $rules, true) ? $schema->string()->required() : $schema->string();
            }

            return $schema->array()->items($schema->object($parts));
        }

        return match ($detail['type']) {
            'image' => $schema->string()->format('uri'),
            Field::TYPE_INTEGER => $schema->integer(),
            Field::TYPE_NUMBER => $schema->number(),
            Field::TYPE_BOOLEAN => $schema->boolean(),
            Field::TYPE_DATETIME => $schema->string()->format('date-time'),
            Field::TYPE_DATE => $schema->string()->format('date'),
            Field::TYPE_ARRAY => $schema->array(),
            Field::TYPE_OBJECT => $schema->object(),
            default => $schema->string(),
        };
    }

    protected function describe(Field $field): string
    {
        $parts = [$field->description !== '' ? $field->description : $field->label.'.'];

        if ($field->enum !== null && $field->enum !== []) {
            $parts[] = 'Allowed: '.implode(', ', array_map(fn (mixed $value): string => var_export($value, true), $field->enum)).'.';
        }

        if ($field->isRelation()) {
            $parts[] = ($field->relation['creatable'] ?? false)
                ? 'New values are created when they do not exist yet.'
                : 'Only existing values; get_post_fields lists them.';

            if ($field->relationDetails() !== []) {
                $one = Str::singular($field->name);
                $label = (string) ($field->relation['label'] ?? 'id');
                $growing = array_keys(array_filter($field->relationDetails(), fn (array $detail): bool => $detail['grow']));
                $parts[] = "To fill in a new {$one}, send an object with \"{$label}\" and its details (".implode(', ', array_keys($field->relationDetails())).'). '
                    ."For an existing {$one}, empty details are filled in"
                    .($growing === [] ? '' : ' and new entries are added to '.implode(', ', $growing))
                    .'; details and entries it already has are never changed or removed. Images are given as a public URL.';
            }

            if ($field->isMultipleRelation()) {
                $parts[] = 'Sending a list replaces the current one.';
            }
        }

        if ($field->notes !== null && $field->role === 'slug') {
            $parts[] = $field->notes;
        }

        return implode(' ', $parts);
    }

    protected function requiredOnCreate(Field $field): bool
    {
        if (! $field->required || $field->isRelation()) {
            return false;
        }

        return ! ($field->role === 'slug' && in_array($this->postMcp->slugStrategy(), ['from_title', 'model'], true));
    }
}
