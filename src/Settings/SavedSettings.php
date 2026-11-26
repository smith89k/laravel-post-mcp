<?php

namespace Smith89k\PostMcp\Settings;

use Smith89k\PostMcp\Fields\Field;
use Smith89k\PostMcp\Fields\FieldResolver;

/**
 * Applies the settings page's choices to a field, inside the limits the config
 * file and the model set: a choice can switch something off or back on, but
 * never open what the config keeps closed.
 */
class SavedSettings
{
    /**
     * Why each switch of a field cannot move, in plain words, or null when it can.
     *
     * @return array{hide: string|null, change: string|null, create: string|null}
     */
    public function locks(Field $field): array
    {
        $hide = match (true) {
            preg_match(FieldResolver::SECRET_PATTERN, $field->name) === 1 => 'Looks like a secret, so it is always hidden.',
            ! $field->readable => 'Turned off by your developer.',
            $field->role !== null => 'Needed to write posts.',
            default => null,
        };

        $change = match (true) {
            ! $field->readable => $hide,
            ! $field->writable => 'It can only be read.',
            $field->role !== null => 'Needed to write posts.',
            default => null,
        };

        $create = match (true) {
            ! $field->isRelation() => null,
            ! $field->readable => $hide,
            ! $field->writable => 'These links can only be read.',
            default => null,
        };

        return ['hide' => $hide, 'change' => $change, 'create' => $create];
    }

    /**
     * @param  array{readable?: bool, writable?: bool, creatable?: bool, details_off?: list<string>, grow_off?: list<string>, label?: string, description?: string}  $saved
     */
    public function apply(Field $field, array $saved): void
    {
        $locks = $this->locks($field);

        if (($saved['readable'] ?? true) === false && $locks['hide'] === null) {
            $field->readable = false;
            $field->writable = false;
        }

        if (($saved['writable'] ?? true) === false && $locks['change'] === null) {
            $field->writable = false;
        }

        if (isset($saved['creatable']) && $locks['create'] === null) {
            $field->relation['creatable'] = (bool) $saved['creatable'];
        }

        if (isset($saved['details_off'], $field->relation['details']) && $locks['create'] === null) {
            $field->relation['details'] = array_diff_key($field->relation['details'], array_flip($saved['details_off']));
        }

        if (isset($saved['grow_off'], $field->relation['details']) && $locks['create'] === null) {
            foreach ($saved['grow_off'] as $name) {
                if (isset($field->relation['details'][$name])) {
                    $field->relation['details'][$name]['grow'] = false;
                }
            }
        }

        if (filled($saved['label'] ?? null)) {
            $field->label = (string) $saved['label'];
        }

        if (filled($saved['description'] ?? null)) {
            $field->description = (string) $saved['description'];
        }

        $field->sources[] = 'settings page';
    }
}
