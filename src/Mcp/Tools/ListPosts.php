<?php

namespace Smith89k\PostMcp\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Smith89k\PostMcp\Fields\Field;

#[IsReadOnly]
#[IsIdempotent]
class ListPosts extends PostTool
{
    protected string $name = 'list_posts';

    protected string $title = 'List posts';

    protected string $description = 'Lists posts, newest first, with filters for status, a search text, related values (such as a tag) and a publish date range. Returns short rows; use get_post for the full post.';

    public function schema(JsonSchema $schema): array
    {
        $properties = [
            'status' => $schema->string()->enum($this->status->statuses())->description('Only posts with this status.'),
            'search' => $schema->string()->description('Text to look for in '.implode(', ', $this->searchColumns()).'.'),
            'from' => $schema->string()->format('date-time')->description('Only posts published on or after this date (ISO 8601).'),
            'to' => $schema->string()->format('date-time')->description('Only posts published on or before this date (ISO 8601).'),
            'page' => $schema->integer()->min(1)->description('Page number, starting at 1.'),
            'per_page' => $schema->integer()->min(1)->max($this->maxPerPage())->description('Posts per page, at most '.$this->maxPerPage().'.'),
        ];

        foreach ($this->fields()->readableRelations() as $name => $field) {
            $properties[$name] = $schema->string()->description("Only posts with this {$name} ({$field->relation['label']} or id).");
        }

        return $properties;
    }

    protected function run(Request $request, Authenticatable $user): Response|ResponseFactory
    {
        $map = $this->fields();
        $relations = $map->readableRelations();

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', $this->status->statuses())],
            'search' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$this->maxPerPage()],
            ...array_map(fn () => ['nullable', 'string', 'max:200'], $relations),
        ]);

        $query = $this->postMcp->query()->with(array_keys($relations));

        if (filled($validated['status'] ?? null)) {
            $this->status->scope($query, $validated['status']);
        }

        if (filled($validated['search'] ?? null)) {
            $term = '%'.addcslashes($validated['search'], '%_\\').'%';
            $query->where(function (Builder $query) use ($term): void {
                foreach ($this->searchColumns() as $column) {
                    $query->orWhere($query->qualifyColumn($column), 'like', $term);
                }
            });
        }

        foreach ($relations as $name => $field) {
            if (filled($validated[$name] ?? null)) {
                $this->filterByRelation($query, $field, (string) $validated[$name]);
            }
        }

        $dateColumn = $this->dateColumn();

        if ($dateColumn !== null && filled($validated['from'] ?? null)) {
            $query->where($query->qualifyColumn($dateColumn), '>=', Carbon::parse($validated['from']));
        }

        if ($dateColumn !== null && filled($validated['to'] ?? null)) {
            $query->where($query->qualifyColumn($dateColumn), '<=', Carbon::parse($validated['to']));
        }

        $order = (string) ($this->postMcp->config('list.order_by') ?: ($dateColumn ?? $query->getModel()->getKeyName()));
        $query->orderByDesc($query->qualifyColumn($order))->orderByDesc($query->getModel()->getQualifiedKeyName());

        $perPage = (int) ($validated['per_page'] ?? $this->postMcp->config('list.per_page', 15));
        $page = (int) ($validated['page'] ?? 1);
        $total = (clone $query)->toBase()->getCountForPagination();
        $posts = $query->forPage($page, $perPage)->get();

        return Response::structured([
            'posts' => $posts->map(fn (Model $post): array => $this->presenter->summary($post, $map))->all(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function filterByRelation(Builder $query, Field $field, string $value): void
    {
        $label = (string) ($field->relation['label'] ?? 'id');

        $query->whereHas($field->name, function (Builder $related) use ($label, $value): void {
            $related->where(function (Builder $related) use ($label, $value): void {
                $related->where($related->qualifyColumn($label), $value);

                if (ctype_digit($value)) {
                    $related->orWhere($related->getModel()->getQualifiedKeyName(), (int) $value);
                }
            });
        });
    }

    /**
     * @return list<string>
     */
    protected function searchColumns(): array
    {
        $map = $this->fields();
        $columns = [];

        foreach ((array) $this->postMcp->config('list.search', [$this->postMcp->titleColumn()]) as $column) {
            $field = is_string($column) ? $map->get($column) : null;

            if ($field !== null && $field->readable && ! $field->isRelation()) {
                $columns[] = $field->name;
            }
        }

        return $columns !== [] ? $columns : [$this->postMcp->titleColumn()];
    }

    protected function dateColumn(): ?string
    {
        return $this->postMcp->publishedAtColumn() ?? ($this->postMcp->newModel()->usesTimestamps() ? $this->postMcp->newModel()->getCreatedAtColumn() : null);
    }

    protected function maxPerPage(): int
    {
        return (int) $this->postMcp->config('list.max_per_page', 50);
    }
}
