<?php

namespace Smith89k\PostMcp\Status;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Smith89k\PostMcp\Exceptions\PostMcpException;
use Smith89k\PostMcp\PostMcp;
use UnitEnum;

/**
 * Reads and changes whether a post is a draft, published or scheduled, for both
 * a boolean flag and a status column.
 */
class StatusManager
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const SCHEDULED = 'scheduled';

    public function __construct(protected PostMcp $postMcp) {}

    /**
     * @return list<string>
     */
    public function statuses(): array
    {
        return $this->postMcp->supportsScheduling()
            ? [self::DRAFT, self::PUBLISHED, self::SCHEDULED]
            : [self::DRAFT, self::PUBLISHED];
    }

    public function statusOf(Model $post): string
    {
        $column = $this->postMcp->statusColumn();
        $date = $this->publishDate($post);

        if ($column === null) {
            return $date !== null && $date->isFuture() ? self::SCHEDULED : self::PUBLISHED;
        }

        $value = $this->raw($post->getAttribute($column));

        if ($this->postMcp->statusMode() === 'boolean') {
            if (! $value) {
                return self::DRAFT;
            }

            return $date !== null && $date->isFuture() ? self::SCHEDULED : self::PUBLISHED;
        }

        $scheduled = $this->value(self::SCHEDULED);

        if ($scheduled !== null && $value === $this->raw($scheduled)) {
            return $date !== null && $date->isPast() ? self::PUBLISHED : self::SCHEDULED;
        }

        if ($value === $this->raw($this->value(self::PUBLISHED))) {
            return $date !== null && $date->isFuture() ? self::SCHEDULED : self::PUBLISHED;
        }

        return self::DRAFT;
    }

    /**
     * Sets the attributes for a status without saving.
     *
     * @return array<string, mixed> the attributes to save
     */
    public function attributesFor(string $status, Model $post, ?DateTimeInterface $at = null): array
    {
        $column = $this->postMcp->statusColumn();
        $dateColumn = $this->postMcp->publishedAtColumn();
        $attributes = [];

        if ($status === self::SCHEDULED) {
            if ($dateColumn === null) {
                throw new PostMcpException('This site has no publish date, so posts cannot be scheduled.');
            }

            if ($at === null || Carbon::instance($at)->isPast()) {
                throw new PostMcpException('The publish_at date must be in the future, in ISO 8601 format such as 2030-01-31T09:00:00+00:00.');
            }
        }

        if ($column !== null) {
            $attributes[$column] = match ($status) {
                self::DRAFT => $this->value(self::DRAFT),
                self::PUBLISHED => $this->value(self::PUBLISHED),
                self::SCHEDULED => $this->value(self::SCHEDULED) ?? $this->value(self::PUBLISHED),
                default => throw new PostMcpException("Unknown status \"{$status}\". Use one of: ".implode(', ', $this->statuses()).'.'),
            };
        }

        if ($dateColumn !== null) {
            $current = $this->publishDate($post);

            $attributes[$dateColumn] = match ($status) {
                self::PUBLISHED => $at ?? ($current !== null && $current->isPast() ? $current : Carbon::now()),
                self::SCHEDULED => $at,
                default => $this->postMcp->config('status.clear_date_on_unpublish', false) ? null : $current,
            };
        }

        return $attributes;
    }

    /**
     * @param  Builder<Model>  $query
     */
    public function scope(Builder $query, string $status): void
    {
        $column = $this->postMcp->statusColumn();
        $dateColumn = $this->postMcp->publishedAtColumn();
        $now = Carbon::now();
        $model = $query->getModel();
        $column = $column !== null ? $model->qualifyColumn($column) : null;
        $dateColumn = $dateColumn !== null ? $model->qualifyColumn($dateColumn) : null;

        $future = fn (Builder $query) => $dateColumn !== null ? $query->where($dateColumn, '>', $now) : $query->whereRaw('1 = 0');
        $notFuture = fn (Builder $query) => $dateColumn !== null
            ? $query->where(fn (Builder $query) => $query->whereNull($dateColumn)->orWhere($dateColumn, '<=', $now))
            : $query;

        if ($column === null) {
            $status === self::SCHEDULED ? $future($query) : ($status === self::PUBLISHED ? $notFuture($query) : $query->whereRaw('1 = 0'));

            return;
        }

        $published = $this->raw($this->value(self::PUBLISHED));
        $scheduled = $this->value(self::SCHEDULED);
        $scheduled = $scheduled !== null ? $this->raw($scheduled) : null;

        if ($this->postMcp->statusMode() === 'boolean') {
            match ($status) {
                self::DRAFT => $query->where(fn (Builder $query) => $query->where($column, false)->orWhereNull($column)),
                self::PUBLISHED => $notFuture($query->where($column, true)),
                self::SCHEDULED => $future($query->where($column, true)),
                default => null,
            };

            return;
        }

        $live = array_values(array_filter([$published, $scheduled], fn ($value) => $value !== null));

        match ($status) {
            self::DRAFT => $query->where(fn (Builder $query) => $query->whereNotIn($column, $live)->orWhereNull($column)),
            self::PUBLISHED => $query->where(fn (Builder $query) => $notFuture($query->where($column, $published))
                ->when($scheduled !== null && $dateColumn !== null, fn (Builder $query) => $query->orWhere(
                    fn (Builder $query) => $query->where($column, $scheduled)->where($dateColumn, '<=', $now)
                ))),
            self::SCHEDULED => $query->where(fn (Builder $query) => $future($query->whereIn($column, $live))
                ->when($scheduled !== null && $dateColumn === null, fn (Builder $query) => $query->orWhere($column, $scheduled))),
            default => null,
        };
    }

    protected function value(string $status): mixed
    {
        $values = (array) $this->postMcp->config('status.values', []);

        if ($this->postMcp->statusMode() === 'boolean') {
            return match ($status) {
                self::DRAFT => (bool) ($values[self::DRAFT] ?? false),
                default => (bool) ($values[self::PUBLISHED] ?? true),
            };
        }

        return $values[$status] ?? null;
    }

    protected function raw(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($this->postMcp->statusMode() === 'boolean') {
            return (bool) $value;
        }

        return $value;
    }

    protected function publishDate(Model $post): ?Carbon
    {
        $column = $this->postMcp->publishedAtColumn();

        if ($column === null) {
            return null;
        }

        $value = $post->getAttribute($column);

        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);
    }
}
