<?php

namespace Smith89k\PostMcp\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Smith89k\PostMcp\Exceptions\PostMcpException;
use Smith89k\PostMcp\Fields\Field;
use Smith89k\PostMcp\Media\ImageFetcher;
use Smith89k\PostMcp\Media\ImageFile;

/**
 * Turns the labels, ids or detail objects Claude sends for a relation into related keys,
 * creating missing records, filling in their empty details and adding new list entries when the relation allows it.
 *
 * Every item is checked before anything is written, so one bad item changes nothing.
 */
class RelationResolver
{
    /**
     * How many valid values to show Claude in an error.
     */
    public const SUGGESTION_LIMIT = 50;

    public function __construct(protected ImageFetcher $images) {}

    /**
     * @return list<int|string>|int|string|null
     */
    public function resolve(Field $field, mixed $value): array|int|string|null
    {
        if ($field->isMultipleRelation()) {
            $values = match (true) {
                $value === null => [],
                is_array($value) && array_is_list($value) => $value,
                default => [$value],
            };
        } elseif ($value === null || $value === '') {
            return null;
        } else {
            $values = [$value];
        }

        $items = array_map(fn (mixed $item): array => $this->check($field, $item), $values);
        $keys = array_map(fn (array $item): int|string => $this->save($field, $item), $items);

        return $field->isMultipleRelation() ? array_values(array_unique($keys, SORT_REGULAR)) : $keys[0];
    }

    /**
     * Valid labels for a relation, for get_post_fields and error messages.
     *
     * @return list<string>
     */
    public function options(Field $field, int $limit = 200): array
    {
        $related = $this->relatedModel($field);
        $label = $this->labelColumn($field, $related);

        return $related->newQuery()
            ->orderBy($label)
            ->limit($limit)
            ->pluck($label)
            ->map(fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }

    /**
     * Finds the item, validates what would be written and downloads its images, without saving anything.
     *
     * @return array{value: int|string, existing: Model|null, details: array<string, mixed>}
     */
    protected function check(Field $field, mixed $item): array
    {
        $related = $this->relatedModel($field);
        $label = $this->labelColumn($field, $related);
        $details = [];

        if (is_array($item)) {
            $details = $item;
            $item = $details[$label] ?? $details['id'] ?? null;
            unset($details[$label], $details['id']);
        }

        if (! is_scalar($item) || $item === '') {
            $this->fail($field, 'Each value must be a '.$label.' (text) or an id (number)'.($field->relationDetails() !== [] ? ', or an object with a "'.$label.'" and its details.' : '.'));
        }

        $value = is_string($item) ? $item : (string) $item;
        $existing = $this->find($related, $label, $item);

        if ($existing === null && ! ($field->relation['creatable'] ?? false)) {
            $options = $this->options($field, self::SUGGESTION_LIMIT);
            $list = $options === [] ? 'There are none yet.' : 'Valid values: '.implode(', ', $options).'.';

            $this->fail($field, "\"{$value}\" is not an existing {$field->name} value. {$list}");
        }

        if ($existing === null) {
            $validator = Validator::make(
                ['value' => $value],
                ['value' => ['string', ...($field->relation['label_rules'] ?? [])]],
                [],
                ['value' => "new {$field->name} {$label}"],
            );

            if ($validator->fails()) {
                $this->fail($field, (string) $validator->errors()->first('value'));
            }
        }

        $details = $this->checkDetails($field, $value, $label, $details, $existing);

        return ['value' => is_int($item) ? $item : $value, 'existing' => $existing, 'details' => $details];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed> the details to write, with images downloaded
     */
    protected function checkDetails(Field $field, string $value, string $label, array $details, ?Model $existing): array
    {
        $details = array_filter($details, fn (mixed $detail): bool => $detail !== null && $detail !== '' && $detail !== []);

        if ($details === []) {
            return [];
        }

        $allowed = $field->relationDetails();

        if ($allowed === []) {
            $this->fail($field, ($field->relation['details'] ?? []) !== []
                ? "{$value}: only existing {$field->name} can be linked here, so details cannot be saved. Send just the {$label}."
                : "{$value}: only the {$label} can be sent for {$field->name}.");
        }

        foreach (array_keys($details) as $name) {
            if (! isset($allowed[$name])) {
                $this->fail($field, "{$value}: {$name} cannot be filled in. Details that can be filled in: ".implode(', ', array_keys($allowed)).'.');
            }
        }

        $rules = [];
        $names = [];

        foreach (array_keys($details) as $name) {
            $rules[$name] = $allowed[$name]['rules'];
            $names[$name] = $name;

            foreach ($allowed[$name]['each'] as $part => $partRules) {
                $rules["{$name}.*"] = ['array'];
                $rules["{$name}.*.{$part}"] = $partRules;
                $names["{$name}.*.{$part}"] = "{$name} {$part}";
            }
        }

        $validator = Validator::make($details, $rules, [], $names);

        if ($validator->fails()) {
            $this->fail($field, "{$value}: ".$validator->errors()->first());
        }

        $shapes = [];

        foreach ($details as $name => $detail) {
            if ($allowed[$name]['type'] === Field::TYPE_ARRAY && $allowed[$name]['each'] === [] && is_array($detail)) {
                $shapes[$name] = $this->guessShape($field, $name);
                $this->checkShape($field, $value, $name, $detail, $shapes[$name]);
            }
        }

        $toWrite = [];

        foreach ($details as $name => $detail) {
            if ($existing !== null && filled($existing->getAttribute($name))) {
                if (! $allowed[$name]['grow'] || ! is_array($detail)) {
                    continue;
                }

                $key = $allowed[$name]['each'] !== []
                    ? (string) array_key_first($allowed[$name]['each'])
                    : $this->identityKey($shapes[$name] ?? null);

                $detail = $this->addToList((array) $existing->getAttribute($name), $detail, $key);

                if ($detail === null) {
                    continue;
                }
            }

            if ($allowed[$name]['image'] !== null) {
                try {
                    $detail = $this->images->fromUrl((string) $detail, $value);
                } catch (PostMcpException $exception) {
                    $this->fail($field, "{$value}: {$name}: ".$exception->getMessage());
                }
            }

            $toWrite[$name] = $detail;
        }

        return $toWrite;
    }

    /**
     * @param  array{value: int|string, existing: Model|null, details: array<string, mixed>}  $item
     */
    protected function save(Field $field, array $item): int|string
    {
        $related = $this->relatedModel($field);
        $label = $this->labelColumn($field, $related);

        // An earlier item in the same list may have just created it.
        $model = $item['existing'] ?? $this->find($related, $label, $item['value']);

        if ($model === null) {
            $model = $related->newInstance();
            $model->forceFill([$label => (string) $item['value']]);
        }

        // Details were checked against the item as found; one created earlier in this list
        // only gets the details that are still empty.
        $details = $item['existing'] !== null ? $item['details'] : array_filter(
            $item['details'],
            fn (string $name): bool => ! $model->exists || blank($model->getAttribute($name)),
            ARRAY_FILTER_USE_KEY,
        );

        if ($model->exists && $details === []) {
            return $model->getKey();
        }

        $stored = [];

        foreach ($details as $name => $detail) {
            if ($detail instanceof ImageFile) {
                $image = $field->relationDetails()[$name]['image'] ?? ['disk' => (string) config('filesystems.default'), 'directory' => ''];
                $path = ltrim($image['directory'].'/'.$detail->filename, '/');

                Storage::disk($image['disk'])->put($path, $detail->contents);
                $stored[] = [$image['disk'], $path];
                $detail = $path;
            }

            $model->setAttribute($name, $detail);
        }

        try {
            $model->save();
        } catch (QueryException) {
            foreach ($stored as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }

            $this->fail($field, $model->exists
                ? "Could not save the details of {$item['value']}."
                : "Could not create a new {$field->name} from just a {$label}. Create it on the site first, or use an existing one.");
        }

        return $model->getKey();
    }

    /**
     * What an entry of a list nobody described looks like, read from up to 50 saved items:
     * the parts its entries have, or null parts when entries are plain values. Null when
     * nothing is saved yet.
     *
     * @return array{parts: list<string>|null}|null
     */
    protected function guessShape(Field $field, string $column): ?array
    {
        $related = $this->relatedModel($field);
        $parts = [];
        $objects = 0;
        $plain = 0;

        $related->newQuery()->whereNotNull($column)->latest($related->getKeyName())->limit(50)->get()
            ->each(function (Model $item) use ($column, &$parts, &$objects, &$plain): void {
                foreach ((array) $item->getAttribute($column) as $entry) {
                    if (is_array($entry)) {
                        $objects++;
                        $parts = [...$parts, ...array_diff(array_map('strval', array_keys($entry)), $parts)];
                    } elseif ($entry !== null) {
                        $plain++;
                    }
                }
            });

        return match (true) {
            $objects > 0 && $objects >= $plain => ['parts' => $parts],
            $plain > 0 => ['parts' => null],
            default => null,
        };
    }

    /**
     * New entries must look like the saved ones: the same kind, and no parts they never have.
     *
     * @param  array<int|string, mixed>  $entries
     * @param  array{parts: list<string>|null}|null  $shape
     */
    protected function checkShape(Field $field, string $value, string $name, array $entries, ?array $shape): void
    {
        if ($shape === null) {
            return;
        }

        foreach ($entries as $entry) {
            if ($shape['parts'] === null && ! is_scalar($entry)) {
                $this->fail($field, "{$value}: each {$name} entry must be plain text.");
            }

            if ($shape['parts'] !== null && (! is_array($entry) || array_diff(array_map('strval', array_keys($entry)), $shape['parts']) !== [])) {
                $this->fail($field, "{$value}: each {$name} entry can only have: ".implode(', ', $shape['parts']).'.');
            }
        }
    }

    /**
     * Which part tells two entries apart: a usual one such as the address or id, or none
     * (then the whole entry is compared).
     *
     * @param  array{parts: list<string>|null}|null  $shape
     */
    protected function identityKey(?array $shape): ?string
    {
        foreach (['url', 'href', 'link', 'id', 'slug', 'email', 'name'] as $usual) {
            if (in_array($usual, $shape['parts'] ?? [], true)) {
                return $usual;
            }
        }

        return null;
    }

    /**
     * Adds the entries a list does not have yet, matched by one part (for links, the address)
     * or by the whole entry, and keeps every entry it has as it is. Null when nothing is new.
     *
     * @param  array<int|string, mixed>  $current
     * @param  array<int|string, mixed>  $sent
     * @return list<mixed>|null
     */
    protected function addToList(array $current, array $sent, ?string $key): ?array
    {
        $identify = function (mixed $entry) use ($key): string {
            if (is_array($entry)) {
                if ($key === null) {
                    ksort($entry);

                    return strtolower((string) json_encode($entry));
                }

                $entry = $entry[$key] ?? '';
            }

            return strtolower(rtrim(trim(is_scalar($entry) ? (string) $entry : ''), '/'));
        };
        $known = array_map($identify, $current);
        $merged = array_values($current);

        foreach ($sent as $entry) {
            $id = $identify($entry);

            if ($id !== '' && ! in_array($id, $known, true)) {
                $merged[] = $entry;
                $known[] = $id;
            }
        }

        return count($merged) === count($current) ? null : $merged;
    }

    protected function find(Model $related, string $label, int|string|float|bool $value): ?Model
    {
        $byLabel = $related->newQuery()->where($label, (string) $value)->first();

        if ($byLabel instanceof Model) {
            return $byLabel;
        }

        if (is_int($value) || ctype_digit((string) $value)) {
            $byKey = $related->newQuery()->find($value);

            if ($byKey instanceof Model) {
                return $byKey;
            }
        }

        return null;
    }

    protected function relatedModel(Field $field): Model
    {
        $class = $field->relation['model'] ?? null;

        return is_string($class) && class_exists($class) ? new $class : throw new \LogicException("Relation {$field->name} has no model.");
    }

    protected function labelColumn(Field $field, Model $related): string
    {
        return (string) ($field->relation['label'] ?? $related->getKeyName());
    }

    protected function fail(Field $field, string $message): never
    {
        throw ValidationException::withMessages([$field->name => $message]);
    }
}
