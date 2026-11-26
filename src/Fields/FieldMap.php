<?php

namespace Smith89k\PostMcp\Fields;

/**
 * Every field of the post model with what Claude may do with it.
 */
class FieldMap
{
    /**
     * @param  array<string, Field>  $fields
     */
    public function __construct(protected array $fields) {}

    /**
     * @return array<string, Field>
     */
    public function all(): array
    {
        return $this->fields;
    }

    public function has(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    public function get(string $name): ?Field
    {
        return $this->fields[$name] ?? null;
    }

    public function byRole(string $role): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->role === $role) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Columns and relations Claude can read.
     *
     * @return array<string, Field>
     */
    public function readable(): array
    {
        return array_filter($this->fields, fn (Field $field): bool => $field->readable);
    }

    /**
     * Columns (not relations) Claude can write directly.
     *
     * @return array<string, Field>
     */
    public function writableColumns(): array
    {
        return array_filter($this->fields, fn (Field $field): bool => $field->writable && ! $field->isRelation());
    }

    /**
     * @return array<string, Field>
     */
    public function writableRelations(): array
    {
        return array_filter($this->fields, fn (Field $field): bool => $field->writable && $field->isRelation());
    }

    /**
     * @return array<string, Field>
     */
    public function readableRelations(): array
    {
        return array_filter($this->fields, fn (Field $field): bool => $field->readable && $field->isRelation());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (Field $field): array => $field->toArray(), $this->fields);
    }
}
