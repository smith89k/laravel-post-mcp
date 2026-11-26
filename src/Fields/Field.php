<?php

namespace Smith89k\PostMcp\Fields;

/**
 * One attribute or relationship of the post model, as Claude sees it.
 */
class Field
{
    public const TYPE_STRING = 'string';

    public const TYPE_TEXT = 'text';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_NUMBER = 'number';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_DATETIME = 'datetime';

    public const TYPE_DATE = 'date';

    public const TYPE_ARRAY = 'array';

    public const TYPE_OBJECT = 'object';

    public const TYPE_RELATION = 'relation';

    /**
     * @param  list<string|int|float|bool>|null  $enum
     * @param  list<mixed>  $rules
     * @param  list<string>  $sources
     * @param  array{model?: class-string, multiple?: bool, label?: string, creatable?: bool, label_rules?: list<mixed>, details?: array<string, array{type: string, description: string, rules: list<mixed>, each: array<string, list<mixed>>, image: array{disk: string, directory: string}|null, grow: bool}>}  $relation
     */
    public function __construct(
        public string $name,
        public string $type = self::TYPE_STRING,
        public string $label = '',
        public string $description = '',
        public bool $required = false,
        public bool $nullable = true,
        public ?array $enum = null,
        public mixed $default = null,
        public ?int $maxLength = null,
        public bool $readable = true,
        public bool $writable = true,
        public ?string $role = null,
        public array $rules = [],
        public array $sources = [],
        public array $relation = [],
        public ?string $notes = null,
    ) {}

    public function isRelation(): bool
    {
        return $this->type === self::TYPE_RELATION;
    }

    public function isMultipleRelation(): bool
    {
        return $this->isRelation() && ($this->relation['multiple'] ?? false);
    }

    /**
     * The details Claude may fill in on related items, such as a project's links.
     * Only relations that can create new items have them.
     *
     * @return array<string, array{type: string, description: string, rules: list<mixed>, each: array<string, list<mixed>>, image: array{disk: string, directory: string}|null, grow: bool}>
     */
    public function relationDetails(): array
    {
        if (! $this->isRelation() || ! $this->writable || ! ($this->relation['creatable'] ?? false)) {
            return [];
        }

        return $this->relation['details'] ?? [];
    }

    /**
     * @return array{name: string, type: string, label: string, description: string, required: bool, nullable: bool, enum: list<string|int|float|bool>|null, default: mixed, max_length: int|null, readable: bool, writable: bool, role: string|null, sources: list<string>, relation: array<string, mixed>|null, notes: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'description' => $this->description,
            'required' => $this->required,
            'nullable' => $this->nullable,
            'enum' => $this->enum,
            'default' => $this->default,
            'max_length' => $this->maxLength,
            'readable' => $this->readable,
            'writable' => $this->writable,
            'role' => $this->role,
            'sources' => $this->sources,
            'relation' => $this->isRelation() ? [
                'multiple' => (bool) ($this->relation['multiple'] ?? false),
                'label' => $this->relation['label'] ?? null,
                'creatable' => (bool) ($this->relation['creatable'] ?? false),
                'details' => array_keys($this->relationDetails()),
            ] : null,
            'notes' => $this->notes,
        ];
    }
}
