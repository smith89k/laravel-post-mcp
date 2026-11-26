<?php

namespace Smith89k\PostMcp\Fields;

use BackedEnum;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Smith89k\PostMcp\PostMcp;
use Smith89k\PostMcp\Settings\SavedSettings;
use Smith89k\PostMcp\Settings\SettingsStore;
use Smith89k\PostMcp\Support\FormRequestRules;
use Throwable;
use UnitEnum;

/**
 * Builds the field map from the table, the model and the site's validation rules,
 * then applies the config overrides.
 */
class FieldResolver
{
    /**
     * Column names that are never shown to or written by Claude.
     */
    public const SECRET_PATTERN = '/(^|_)(password|passwd|secret|token|api_?key|private_?key|remember_token|two_factor[a-z_]*)($|_)/i';

    protected const CAST_TYPES = [
        'bool' => Field::TYPE_BOOLEAN,
        'boolean' => Field::TYPE_BOOLEAN,
        'int' => Field::TYPE_INTEGER,
        'integer' => Field::TYPE_INTEGER,
        'float' => Field::TYPE_NUMBER,
        'double' => Field::TYPE_NUMBER,
        'real' => Field::TYPE_NUMBER,
        'decimal' => Field::TYPE_NUMBER,
        'string' => Field::TYPE_STRING,
        'array' => Field::TYPE_ARRAY,
        'json' => Field::TYPE_ARRAY,
        'collection' => Field::TYPE_ARRAY,
        'object' => Field::TYPE_OBJECT,
        'date' => Field::TYPE_DATE,
        'immutable_date' => Field::TYPE_DATE,
        'datetime' => Field::TYPE_DATETIME,
        'immutable_datetime' => Field::TYPE_DATETIME,
        'timestamp' => Field::TYPE_DATETIME,
    ];

    public function __construct(
        protected PostMcp $postMcp,
        protected Cache $cache,
        protected FormRequestRules $formRequestRules,
        protected SettingsStore $settingsStore,
        protected SavedSettings $savedSettings,
    ) {}

    public function resolve(bool $fresh = false): FieldMap
    {
        $ttl = (int) $this->postMcp->config('fields.cache_ttl', 3600);
        $key = $this->cacheKey();

        if (! $fresh && $ttl > 0) {
            $cached = $this->cache->get($key);

            if ($cached instanceof FieldMap) {
                return $cached;
            }
        }

        $map = $this->build();

        if ($ttl > 0) {
            try {
                $this->cache->put($key, $map, $ttl);
            } catch (Throwable) {
                // Rules holding closures cannot be cached; the map is rebuilt each time instead.
            }
        }

        return $map;
    }

    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    /**
     * The field map from the config file alone, before the settings page's choices.
     */
    public function baseline(): FieldMap
    {
        return $this->build(withSavedSettings: false);
    }

    public function build(bool $withSavedSettings = true): FieldMap
    {
        $model = $this->postMcp->newModel();

        $fields = [];

        foreach ($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()) as $column) {
            $field = $this->fromColumn($model, $column);
            $fields[$field->name] = $field;
        }

        $this->applyFormRequestRules($fields);
        $this->applyRoles($fields);
        $this->addRelations($model, $fields);
        $fields = $this->applySelection($fields);
        $this->applyOverrides($fields);

        if ($withSavedSettings) {
            foreach ($this->settingsStore->all() as $name => $saved) {
                if (isset($fields[$name])) {
                    $this->savedSettings->apply($fields[$name], $saved);
                }
            }
        }

        return new FieldMap($fields);
    }

    /**
     * @param  array{name: string, type_name: string, type: string, nullable: bool, default: mixed, auto_increment: bool, comment: string|null, generation?: array<string, mixed>|null}  $column
     */
    protected function fromColumn(Model $model, array $column): Field
    {
        $name = $column['name'];
        $casts = $model->getCasts();
        $cast = $casts[$name] ?? null;
        $sources = ['column'];

        $field = new Field(
            name: $name,
            label: Str::headline($name),
            description: (string) ($column['comment'] ?? ''),
            nullable: (bool) $column['nullable'],
            maxLength: $this->maxLength($column['type']),
        );

        $field->type = $this->columnType($column);
        $field->enum = $this->columnEnum($column['type']);

        if (is_string($cast)) {
            $sources[] = "cast:{$cast}";
            $this->applyCast($field, $cast);
        }

        $field->default = $this->normalizeDefault($column['default'], $field->type);
        $field->required = ! $field->nullable && $field->default === null && ! $column['auto_increment'];

        $isSecret = preg_match(self::SECRET_PATTERN, $name) === 1 || $cast === 'hashed' || str_starts_with((string) $cast, 'encrypted');
        $isHidden = in_array($name, $model->getHidden(), true);
        $isKey = $name === $model->getKeyName() || $column['auto_increment'];
        $isTimestamp = in_array($name, array_filter([
            $model->getCreatedAtColumn(),
            $model->getUpdatedAtColumn(),
            method_exists($model, 'getDeletedAtColumn') ? $model->getDeletedAtColumn() : 'deleted_at',
        ]), true);
        $isGenerated = ! empty($column['generation']);
        $isFillable = $model->isFillable($name);

        $sources[] = $isFillable ? 'fillable' : 'guarded';

        if ($isHidden || $isSecret) {
            $field->readable = false;
            $field->writable = false;
            $field->notes = $isSecret ? 'Looks like a secret, never exposed.' : 'In $hidden, never exposed.';
        } elseif ($isKey || $isTimestamp || $isGenerated) {
            $field->writable = false;
            $field->notes = 'Set by the database or Eloquent.';
        } elseif (! $isFillable) {
            $field->writable = false;
            $field->notes = 'Guarded (not mass assignable), read-only.';
        }

        if (! $field->writable) {
            $field->required = false;
        }

        $field->sources = $sources;

        return $field;
    }

    /**
     * @param  array{type_name: string, type: string}  $column
     */
    protected function columnType(array $column): string
    {
        $typeName = strtolower($column['type_name']);
        $type = strtolower($column['type']);

        return match (true) {
            $typeName === 'tinyint' && str_contains($type, 'tinyint(1)') => Field::TYPE_BOOLEAN,
            in_array($typeName, ['bool', 'boolean'], true) => Field::TYPE_BOOLEAN,
            in_array($typeName, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'int2', 'int4', 'int8', 'serial', 'bigserial'], true) => Field::TYPE_INTEGER,
            in_array($typeName, ['decimal', 'numeric', 'float', 'double', 'real', 'float4', 'float8', 'money'], true) => Field::TYPE_NUMBER,
            in_array($typeName, ['text', 'mediumtext', 'longtext', 'clob'], true) => Field::TYPE_TEXT,
            $typeName === 'date' => Field::TYPE_DATE,
            in_array($typeName, ['datetime', 'timestamp', 'timestamptz', 'datetimeoffset', 'datetime2'], true) => Field::TYPE_DATETIME,
            in_array($typeName, ['json', 'jsonb'], true) => Field::TYPE_ARRAY,
            default => Field::TYPE_STRING,
        };
    }

    protected function applyCast(Field $field, string $cast): void
    {
        $base = strtolower(Str::before($cast, ':'));

        if (isset(self::CAST_TYPES[$base])) {
            $field->type = self::CAST_TYPES[$base];

            return;
        }

        $class = Str::before($cast, ':');

        if (enum_exists($class)) {
            $field->enum = array_map(
                fn (UnitEnum $case): string|int => $case instanceof BackedEnum ? $case->value : $case->name,
                $class::cases(),
            );
            $first = $field->enum[0] ?? null;
            $field->type = is_int($first) ? Field::TYPE_INTEGER : Field::TYPE_STRING;

            return;
        }

        if (class_exists($class) && preg_match('/(ArrayObject|Collection|AsArray|AsJson)/', $class) === 1) {
            $field->type = Field::TYPE_ARRAY;
        }
    }

    /**
     * @return list<string>|null
     */
    protected function columnEnum(string $type): ?array
    {
        if (preg_match('/^enum\((.*)\)$/i', trim($type), $matches) !== 1) {
            return null;
        }

        $values = str_getcsv($matches[1], ',', "'", '\\');

        return array_values(array_map(fn (?string $value): string => (string) $value, $values));
    }

    protected function maxLength(string $type): ?int
    {
        if (preg_match('/^(var)?char\((\d+)\)/i', trim($type), $matches) === 1) {
            return (int) $matches[2];
        }

        return null;
    }

    protected function normalizeDefault(mixed $default, string $type): mixed
    {
        if ($default === null) {
            return null;
        }

        $value = is_string($default) ? trim($default) : $default;

        if (is_string($value)) {
            if (strtoupper($value) === 'NULL' || str_contains(strtoupper($value), 'CURRENT_TIMESTAMP') || str_contains($value, '(')) {
                return null;
            }

            $value = preg_replace('/::[a-z ]+$/i', '', $value) ?? $value;
            $value = trim($value, "'\"");
        }

        return match ($type) {
            Field::TYPE_BOOLEAN => in_array(strtolower((string) $value), ['1', 'true', 't'], true),
            Field::TYPE_INTEGER => is_numeric($value) ? (int) $value : null,
            Field::TYPE_NUMBER => is_numeric($value) ? (float) $value : null,
            default => $value,
        };
    }

    /**
     * Uses the site's own FormRequest rules to fill in what the table cannot tell.
     *
     * @param  array<string, Field>  $fields
     */
    protected function applyFormRequestRules(array $fields): void
    {
        $class = $this->postMcp->config('validation.create');

        if (! is_string($class) || $class === '') {
            return;
        }

        foreach ($this->formRequestRules->rules($class) as $attribute => $rules) {
            if (! isset($fields[$attribute])) {
                continue;
            }

            $field = $fields[$attribute];
            $field->sources[] = 'form_request';

            foreach ($this->formRequestRules->normalize($rules) as $rule) {
                if (! is_string($rule)) {
                    continue;
                }

                [$ruleName, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

                match (strtolower($ruleName)) {
                    'required' => $field->required = $field->writable,
                    'nullable' => $field->nullable = true,
                    'max' => $field->maxLength = is_numeric($parameter) ? (int) $parameter : $field->maxLength,
                    'in' => $field->enum = array_map(fn (string $value): string => trim($value, '"'), explode(',', (string) $parameter)),
                    'boolean' => $field->type = Field::TYPE_BOOLEAN,
                    'integer' => $field->type = Field::TYPE_INTEGER,
                    'numeric' => $field->type = Field::TYPE_NUMBER,
                    'date' => $field->type = $field->type === Field::TYPE_DATE ? Field::TYPE_DATE : Field::TYPE_DATETIME,
                    'array' => $field->type = Field::TYPE_ARRAY,
                    default => null,
                };
            }
        }
    }

    /**
     * Marks the columns the package manages: title, slug, content, status, dates and author.
     *
     * @param  array<string, Field>  $fields
     */
    protected function applyRoles(array $fields): void
    {
        $roles = [
            'title' => $this->postMcp->titleColumn(),
            'slug' => $this->postMcp->slugColumn(),
            'content' => $this->postMcp->contentColumn(),
            'status' => $this->postMcp->statusColumn(),
            'published_at' => $this->postMcp->publishedAtColumn(),
            'author' => $this->postMcp->authorColumn(),
        ];

        foreach ($roles as $role => $column) {
            if ($column === null || ! isset($fields[$column])) {
                continue;
            }

            $field = $fields[$column];
            $field->role = $role;
            $field->sources[] = 'config';

            match ($role) {
                'title' => $field->description = $field->description ?: 'The post title.',
                'slug' => $this->configureSlug($field),
                'content' => $this->configureContent($field),
                'status', 'published_at' => $this->manage($field, 'Changed with publish_post, unpublish_post and schedule_post.'),
                'author' => $this->manage($field, 'Set to the signed-in user when a post is created.'),
            };
        }
    }

    protected function configureSlug(Field $field): void
    {
        $strategy = $this->postMcp->slugStrategy();
        $reserved = (array) $this->postMcp->config('slug.reserved', []);

        if (in_array($strategy, ['from_title', 'model'], true)) {
            $field->required = false;
        }

        $field->description = $field->description ?: match ($strategy) {
            'from_title' => 'The URL part of the post. Leave it out on create to make one from the title. Letters, numbers, dashes and underscores. Changing it breaks existing links.',
            'model' => 'The URL part of the post. Leave it out and the site fills it in.',
            default => 'The URL part of the post.',
        };

        if ($reserved !== []) {
            $field->notes = 'Reserved: '.implode(', ', $reserved).'.';
        }
    }

    protected function configureContent(Field $field): void
    {
        $format = $this->postMcp->contentInputFormat();

        $field->type = $format === 'blocks' ? Field::TYPE_ARRAY : Field::TYPE_TEXT;
        $field->description = $field->description ?: match ($format) {
            'markdown' => 'The post body in Markdown.',
            'blocks' => 'The post body as a list of content blocks (JSON).',
            default => 'The post body in HTML.',
        };
    }

    protected function manage(Field $field, string $note): void
    {
        $field->writable = false;
        $field->required = false;
        $field->notes = $note;
    }

    /**
     * Lists the model's relationships. Only the configured ones are readable and writable.
     *
     * @param  array<string, Field>  $fields
     */
    protected function addRelations(Model $model, array &$fields): void
    {
        $configured = $this->postMcp->relations();
        $names = array_unique([...$this->discoverRelationNames($model), ...array_keys($configured)]);

        foreach ($names as $name) {
            $relation = $this->relationInstance($model, $name);

            if ($relation === null) {
                continue;
            }

            $settings = $configured[$name] ?? null;
            $isConfigured = is_array($settings);
            $multiple = $relation instanceof BelongsToMany || $relation instanceof HasOneOrMany;
            $syncable = $relation instanceof BelongsToMany || $relation instanceof BelongsTo;
            $related = $relation->getRelated();
            $label = $isConfigured && isset($settings['label']) ? (string) $settings['label'] : $this->guessLabelColumn($related);

            $field = new Field(
                name: $name,
                type: Field::TYPE_RELATION,
                label: Str::headline($name),
                description: $isConfigured ? (string) ($settings['description'] ?? '') : '',
                nullable: true,
                readable: $isConfigured,
                writable: $isConfigured && $syncable,
                sources: ['relation:'.class_basename($relation)],
                relation: [
                    'model' => $related::class,
                    'multiple' => $multiple,
                    'label' => $label,
                    'creatable' => $isConfigured && (bool) ($settings['creatable'] ?? false),
                    'label_rules' => $isConfigured ? array_values((array) ($settings['rules'] ?? [])) : [],
                    'details' => $isConfigured && $syncable ? $this->relationDetails($related, $label, (array) ($settings['details'] ?? [])) : [],
                ],
            );

            if (! $isConfigured) {
                $field->notes = 'Not exposed. Add it to post-mcp.relations to let the AI app use it.';
            } elseif (! $syncable) {
                $field->notes = 'Read-only: only belongsTo and belongsToMany relations can be set.';
            }

            if ($field->description === '' && $isConfigured) {
                $field->description = $multiple
                    ? "A list of {$name} by {$label} (or id)."
                    : "The {$name} by {$label} (or id).";
            }

            if ($relation instanceof BelongsTo && $field->writable && isset($fields[$relation->getForeignKeyName()])) {
                $this->manage($fields[$relation->getForeignKeyName()], "Set through the \"{$name}\" relation.");
            }

            $fields[$name] = $field;
        }
    }

    /**
     * The columns of a relation's related model a person could fill in (no keys, timestamps
     * or generated columns), read the same way as the post's own columns, for the settings page.
     *
     * @return array<string, Field>
     */
    public function relatedColumns(Field $relation): array
    {
        $class = $relation->relation['model'] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            return [];
        }

        /** @var Model $related */
        $related = new $class;
        $columns = [];

        foreach ($related->getConnection()->getSchemaBuilder()->getColumns($related->getTable()) as $column) {
            $isManaged = $column['auto_increment'] || ! empty($column['generation'])
                || in_array($column['name'], [$related->getKeyName(), $related->getCreatedAtColumn(), $related->getUpdatedAtColumn()], true);

            if (! $isManaged) {
                $columns[$column['name']] = $this->fromColumn($related, $column);
            }
        }

        return $columns;
    }

    /**
     * Turns a relation's "details" config into what Claude may fill in on related items.
     * Columns that are missing, secret, hidden, keys, timestamps, guarded or the label itself are left out.
     *
     * @param  array<int|string, mixed>  $configured
     * @return array<string, array{type: string, description: string, rules: list<mixed>, each: array<string, list<mixed>>, image: array{disk: string, directory: string}|null, grow: bool}>
     */
    protected function relationDetails(Model $related, string $label, array $configured): array
    {
        if ($configured === []) {
            return [];
        }

        $columns = [];

        foreach ($related->getConnection()->getSchemaBuilder()->getColumns($related->getTable()) as $column) {
            $columns[$column['name']] = $column;
        }

        $details = [];

        foreach ($configured as $key => $spec) {
            [$name, $spec] = is_int($key) ? [(string) $spec, []] : [(string) $key, (array) $spec];

            if ($name === $label || ! isset($columns[$name])) {
                continue;
            }

            $column = $this->fromColumn($related, $columns[$name]);

            if (! $column->readable || ! $column->writable) {
                continue;
            }

            $image = isset($spec['image']) && is_array($spec['image']) ? [
                'disk' => (string) ($spec['image']['disk'] ?? config('filesystems.default')),
                'directory' => trim((string) ($spec['image']['directory'] ?? ''), '/'),
            ] : null;

            $each = [];

            foreach ((array) ($spec['each'] ?? []) as $part => $partRules) {
                $each[(string) $part] = array_values((array) $partRules);
            }

            $type = match (true) {
                $image !== null => 'image',
                $each !== [] => Field::TYPE_ARRAY,
                default => $column->type,
            };

            $rules = match ($type) {
                'image' => ['string', 'url', 'max:2048'],
                Field::TYPE_STRING, Field::TYPE_TEXT => array_values(array_filter(['string', $column->maxLength !== null ? 'max:'.$column->maxLength : null])),
                Field::TYPE_INTEGER => ['integer'],
                Field::TYPE_NUMBER => ['numeric'],
                Field::TYPE_BOOLEAN => ['boolean'],
                Field::TYPE_DATE, Field::TYPE_DATETIME => ['date'],
                Field::TYPE_ARRAY, Field::TYPE_OBJECT => ['array'],
                default => [],
            };

            $details[$name] = [
                'type' => $type,
                'description' => (string) ($spec['description'] ?? ($column->description !== '' ? $column->description : $column->label.'.')),
                'rules' => [...$rules, ...array_values((array) ($spec['rules'] ?? []))],
                'each' => $each,
                'image' => $image,
                // A list can get new entries on items that already exist, unless the config says no.
                'grow' => $type === Field::TYPE_ARRAY && (bool) ($spec['grow'] ?? true),
            ];
        }

        return $details;
    }

    /**
     * Public relationship methods declared with a Relation return type on the model or its traits.
     *
     * @return list<string>
     */
    protected function discoverRelationNames(Model $model): array
    {
        $names = [];

        foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0 || $method->getDeclaringClass()->getName() === Model::class) {
                continue;
            }

            $type = $method->getReturnType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), Relation::class)) {
                $names[] = $method->getName();
            }
        }

        return $names;
    }

    /**
     * @return Relation<Model, Model, mixed>|null
     */
    protected function relationInstance(Model $model, string $name): ?Relation
    {
        if (! method_exists($model, $name)) {
            return null;
        }

        try {
            $relation = $model->{$name}();
        } catch (Throwable) {
            return null;
        }

        return $relation instanceof Relation ? $relation : null;
    }

    protected function guessLabelColumn(Model $related): string
    {
        try {
            $columns = $related->getConnection()->getSchemaBuilder()->getColumnListing($related->getTable());
        } catch (Throwable) {
            $columns = [];
        }

        foreach (['name', 'title', 'label', 'slug'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return $related->getKeyName();
    }

    /**
     * @param  array<string, Field>  $fields
     * @return array<string, Field>
     */
    protected function applySelection(array $fields): array
    {
        $only = (array) $this->postMcp->config('fields.only', []);
        $except = (array) $this->postMcp->config('fields.except', []);

        if ($this->postMcp->config('fields.discovery', 'auto') === 'explicit') {
            $fields = array_filter(
                $fields,
                fn (Field $field): bool => in_array($field->name, $only, true)
                    || $field->role !== null
                    || ($field->isRelation() && $field->readable),
            );
        }

        foreach ($except as $name) {
            if (isset($fields[$name]) && $fields[$name]->role === null) {
                $fields[$name]->readable = false;
                $fields[$name]->writable = false;
                $fields[$name]->notes = 'Excluded in post-mcp.fields.except.';
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, Field>  $fields
     */
    protected function applyOverrides(array $fields): void
    {
        foreach ((array) $this->postMcp->config('fields.overrides', []) as $name => $override) {
            if (! isset($fields[$name]) || ! is_array($override)) {
                continue;
            }

            $field = $fields[$name];
            $field->sources[] = 'override';
            $isSecret = preg_match(self::SECRET_PATTERN, $name) === 1;

            foreach ($override as $key => $value) {
                match ($key) {
                    'label' => $field->label = (string) $value,
                    'description' => $field->description = (string) $value,
                    'type' => $field->type = (string) $value,
                    'required' => $field->required = (bool) $value,
                    'enum' => $field->enum = array_values((array) $value),
                    'rules' => $field->rules = array_values([...$field->rules, ...(array) $value]),
                    'hidden' => $value ? [$field->readable, $field->writable] = [false, false] : null,
                    'read_only' => $value ? $field->writable = false : null,
                    default => null,
                };
            }

            if ($isSecret) {
                $field->readable = false;
                $field->writable = false;
            }
        }
    }

    protected function cacheKey(): string
    {
        $config = json_encode([$this->postMcp->all(), $this->postMcp->modelClass(), $this->settingsStore->all()], JSON_PARTIAL_OUTPUT_ON_ERROR);

        return 'post-mcp:fields:'.md5((string) $config);
    }
}
