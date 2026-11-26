<?php

namespace Smith89k\PostMcp\Install;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\MediaLibrary\HasMedia;
use Symfony\Component\Finder\Finder;
use Throwable;
use UnitEnum;

/**
 * Looks at a site's post model and guesses the post-mcp settings for it.
 */
class ConfigGuesser
{
    protected const TITLE = ['title', 'name', 'headline', 'subject'];

    protected const CONTENT = ['body', 'content', 'text', 'html', 'markdown', 'body_html', 'content_html', 'body_markdown', 'markdown_text', 'post_content', 'blocks'];

    protected const SLUG = ['slug', 'permalink', 'url_slug', 'handle', 'path'];

    protected const BOOLEAN_STATUS = ['is_published', 'published', 'is_live', 'is_public', 'active', 'is_active'];

    protected const STATUS = ['status', 'state', 'post_status', 'visibility'];

    protected const PUBLISHED_AT = ['published_at', 'publish_at', 'published_on', 'posted_at', 'goes_live_at', 'go_live_at', 'publish_date', 'live_at'];

    protected const AUTHOR = ['user_id', 'author_id', 'created_by'];

    protected const IMAGE = ['featured_image', 'cover_image', 'image', 'hero_image', 'thumbnail', 'image_path', 'cover'];

    protected const PUBLIC_ROUTES = ['posts.show', 'post.show', 'blog.show', 'blog.post', 'articles.show', 'article.show'];

    /**
     * Models whose name suggests a blog post, best guess first.
     *
     * @return list<class-string<Model>>
     */
    public function candidateModels(string $path, string $namespace = 'App\\Models\\'): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $preferred = ['Post', 'BlogPost', 'Article', 'Entry', 'Story', 'News'];
        $found = [];

        foreach (Finder::create()->files()->in($path)->name('*.php') as $file) {
            $class = $namespace.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $found[] = $class;
            }
        }

        usort($found, function (string $a, string $b) use ($preferred): int {
            $rank = fn (string $class): int => ($index = array_search(class_basename($class), $preferred, true)) === false ? 100 : (int) $index;

            return [$rank($a), $a] <=> [$rank($b), $b];
        });

        return $found;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<string, mixed>
     */
    public function guess(string $modelClass): array
    {
        $model = new $modelClass;
        $columns = $this->columns($model);
        $names = array_keys($columns);
        $casts = $model->getCasts();

        $title = $this->first($names, self::TITLE) ?? 'title';
        $content = $this->first($names, self::CONTENT);
        $slug = $this->first($names, self::SLUG);
        $publishedAt = $this->first($names, self::PUBLISHED_AT);

        $guess = [
            'model' => $modelClass,
            'title_column' => $title,
            'author_column' => $this->first($names, self::AUTHOR),
            'status' => $this->guessStatus($names, $columns, $casts, $publishedAt),
            'slug' => [
                'column' => $slug,
                'strategy' => 'from_title',
                'source' => $title,
                'reserved' => [],
            ],
            'content' => [
                'column' => $content,
                'format' => $this->guessFormat($content, $columns[$content] ?? null, $casts[$content ?? ''] ?? null),
                'input_format' => null,
                'converter' => null,
            ],
            'validation' => $this->guessFormRequests($modelClass),
            'relations' => $this->guessRelations($model),
            'media' => $this->guessMedia($model, $names),
            'public_url' => ['route' => $this->guessRoute()],
            'list' => [
                'per_page' => 15,
                'max_per_page' => 50,
                'search' => array_values(array_filter([$title, $content])),
                'order_by' => null,
            ],
        ];

        return $guess;
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, string>  $columns
     * @param  array<string, mixed>  $casts
     * @return array<string, mixed>
     */
    protected function guessStatus(array $names, array $columns, array $casts, ?string $publishedAt): array
    {
        $boolean = $this->first($names, self::BOOLEAN_STATUS);

        if ($boolean !== null) {
            return [
                'mode' => 'boolean',
                'column' => $boolean,
                'values' => ['draft' => false, 'published' => true, 'scheduled' => true],
                'published_at' => $publishedAt,
                'clear_date_on_unpublish' => false,
            ];
        }

        $column = $this->first($names, self::STATUS);
        $options = [];

        if ($column !== null) {
            $cast = $casts[$column] ?? null;

            if (is_string($cast) && enum_exists($cast)) {
                $options = array_map(fn (UnitEnum $case): string|int => $case instanceof BackedEnum ? $case->value : $case->name, $cast::cases());
            } elseif (preg_match('/^enum\((.*)\)$/i', $columns[$column] ?? '', $matches) === 1) {
                $options = str_getcsv($matches[1], ',', "'", '\\');
            }
        }

        $pick = function (array $candidates, mixed $fallback) use ($options): mixed {
            foreach ($options as $option) {
                if (in_array(strtolower((string) $option), $candidates, true)) {
                    return $option;
                }
            }

            return $options === [] ? $fallback : null;
        };

        return [
            'mode' => 'column',
            'column' => $column,
            'values' => [
                'draft' => $pick(['draft', 'hidden', 'unpublished', 'pending', 'private'], 'draft') ?? ($options[0] ?? 'draft'),
                'published' => $pick(['published', 'live', 'public', 'active', 'online'], 'published') ?? 'published',
                'scheduled' => $pick(['scheduled', 'queued', 'future', 'planned'], 'scheduled'),
            ],
            'published_at' => $publishedAt,
            'clear_date_on_unpublish' => false,
        ];
    }

    protected function guessFormat(?string $column, ?string $type, mixed $cast): string
    {
        if ($column === null) {
            return 'html';
        }

        if (in_array($cast, ['array', 'json', 'collection', 'object'], true) || in_array($type, ['json', 'jsonb'], true) || $column === 'blocks') {
            return 'blocks';
        }

        return Str::contains($column, ['markdown', '_md']) ? 'markdown' : 'html';
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{create: class-string|null, update: class-string|null}
     */
    protected function guessFormRequests(string $modelClass): array
    {
        $base = class_basename($modelClass);
        $find = function (array $names): ?string {
            foreach ($names as $name) {
                foreach (["App\\Http\\Requests\\{$name}", "App\\Http\\Requests\\Admin\\{$name}"] as $class) {
                    if (class_exists($class)) {
                        return $class;
                    }
                }
            }

            return null;
        };

        return [
            'create' => $find(["Store{$base}Request", "Create{$base}Request", "{$base}Request"]),
            'update' => $find(["Update{$base}Request", "{$base}Request"]),
        ];
    }

    /**
     * BelongsToMany relations become writable relations, looked up by name or title.
     *
     * @return array<string, array{label: string, creatable: bool}>
     */
    protected function guessRelations(Model $model): array
    {
        $relations = [];

        foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();

            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0 || ! $type instanceof ReflectionNamedType || ! is_a($type->getName(), BelongsToMany::class, true)) {
                continue;
            }

            try {
                $relation = $model->{$method->getName()}();
            } catch (Throwable) {
                continue;
            }

            if (! $relation instanceof Relation) {
                continue;
            }

            $relatedColumns = array_keys($this->columns($relation->getRelated()));

            $relations[$method->getName()] = [
                'label' => $this->first($relatedColumns, ['name', 'title', 'label', 'slug']) ?? $relation->getRelated()->getKeyName(),
                'creatable' => $method->getName() === 'tags',
            ];
        }

        ksort($relations);

        return $relations;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    protected function guessMedia(Model $model, array $names): array
    {
        $usesMediaLibrary = interface_exists('Spatie\\MediaLibrary\\HasMedia') && $model instanceof HasMedia;

        return [
            'driver' => $usesMediaLibrary ? 'medialibrary' : 'disk',
            'disk' => 'public',
            'directory' => Str::plural(Str::snake(class_basename($model))),
            'collection' => 'images',
            'attach_to' => $usesMediaLibrary ? null : $this->first($names, self::IMAGE),
            'attach_value' => 'path',
            'max_kb' => 5120,
            'mimes' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'timeout' => 15,
        ];
    }

    protected function guessRoute(): ?string
    {
        foreach (self::PUBLIC_ROUTES as $name) {
            if (Route::has($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> column name => full type
     */
    protected function columns(Model $model): array
    {
        try {
            $columns = $model->getConnection()->getSchemaBuilder()->getColumns($model->getTable());
        } catch (Throwable) {
            return [];
        }

        $result = [];

        foreach ($columns as $column) {
            $result[$column['name']] = strtolower((string) $column['type']);
        }

        return $result;
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $candidates
     */
    protected function first(array $names, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $names, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
