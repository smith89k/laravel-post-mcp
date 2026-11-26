<?php

namespace Smith89k\PostMcp\Console;

use Illuminate\Console\Command;
use Smith89k\PostMcp\Fields\Field;
use Smith89k\PostMcp\Fields\FieldMap;

/**
 * Prints the field map as a table.
 */
class FieldTable
{
    public static function render(Command $command, FieldMap $map): void
    {
        $rows = [];

        foreach ($map->all() as $field) {
            $rows[] = [
                $field->name,
                $field->isRelation() ? ($field->isMultipleRelation() ? 'relation (many)' : 'relation') : $field->type,
                $field->required ? 'yes' : '',
                self::access($field),
                self::allowed($field),
                $field->role ?? '',
                $field->notes ?? '',
            ];
        }

        $command->table(['field', 'type', 'required', 'access', 'allowed values', 'role', 'notes'], $rows);
    }

    protected static function access(Field $field): string
    {
        return match (true) {
            $field->writable => 'read/write',
            $field->readable => 'read',
            default => 'hidden',
        };
    }

    protected static function allowed(Field $field): string
    {
        if ($field->enum !== null && $field->enum !== []) {
            return implode(', ', array_map('strval', $field->enum));
        }

        if ($field->isRelation()) {
            return 'by '.($field->relation['label'] ?? 'id').(($field->relation['creatable'] ?? false) ? ' (creatable)' : '');
        }

        return $field->maxLength !== null ? 'max '.$field->maxLength : '';
    }
}
