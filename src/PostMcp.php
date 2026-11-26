<?php

namespace Smith89k\PostMcp;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Smith89k\PostMcp\Exceptions\PostMcpException;

/**
 * Reads the post-mcp config and answers questions about the configured post model.
 */
class PostMcp
{
    public function __construct(protected Repository $config) {}

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config->get("post-mcp.{$key}", $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return (array) $this->config->get('post-mcp', []);
    }

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        $class = $this->config('model');

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new PostMcpException('The post-mcp.model setting must be an Eloquent model class. Got: '.var_export($class, true));
        }

        return $class;
    }

    public function newModel(): Model
    {
        $class = $this->modelClass();

        return new $class;
    }

    /**
     * @return Builder<Model>
     */
    public function query(): Builder
    {
        return $this->modelClass()::query();
    }

    public function find(int|string $id): Model
    {
        $post = $this->query()->find($id);

        if (! $post instanceof Model) {
            throw new PostMcpException("No post with id {$id}. Use list_posts to find the right id.");
        }

        return $post;
    }

    public function findBySlug(string $slug): Model
    {
        $column = $this->slugColumn();

        if ($column === null) {
            throw new PostMcpException('This site has no slug column. Look the post up by id instead.');
        }

        $post = $this->query()->where($column, $slug)->first();

        if (! $post instanceof Model) {
            throw new PostMcpException("No post with slug \"{$slug}\". Use list_posts to search for it.");
        }

        return $post;
    }

    public function serverName(): string
    {
        $name = $this->config('server.name');

        return filled($name) ? (string) $name : config('app.name', 'Laravel').' Posts';
    }

    public function titleColumn(): string
    {
        return (string) $this->config('title_column', 'title');
    }

    public function authorColumn(): ?string
    {
        return $this->nullableString('author_column');
    }

    public function slugColumn(): ?string
    {
        return $this->nullableString('slug.column');
    }

    public function slugStrategy(): string
    {
        return (string) $this->config('slug.strategy', 'from_title');
    }

    public function contentColumn(): ?string
    {
        return $this->nullableString('content.column');
    }

    public function contentFormat(): string
    {
        return (string) $this->config('content.format', 'html');
    }

    public function contentInputFormat(): string
    {
        return $this->nullableString('content.input_format') ?? $this->contentFormat();
    }

    public function statusMode(): string
    {
        return (string) $this->config('status.mode', 'column');
    }

    public function statusColumn(): ?string
    {
        return $this->nullableString('status.column');
    }

    public function publishedAtColumn(): ?string
    {
        return $this->nullableString('status.published_at');
    }

    public function supportsScheduling(): bool
    {
        return $this->publishedAtColumn() !== null;
    }

    public function gate(): string
    {
        return (string) $this->config('auth.gate', 'use-post-mcp');
    }

    public function authMode(): string
    {
        return (string) $this->config('auth.mode', 'oauth');
    }

    public function guard(): string
    {
        return (string) $this->config('auth.guard', 'api');
    }

    public function mediaDriver(): string
    {
        return (string) $this->config('media.driver', 'none');
    }

    public function deleteEnabled(): bool
    {
        return (bool) $this->config('delete.enabled', false);
    }

    /**
     * @return array<string, array{label?: string, creatable?: bool, rules?: list<mixed>, description?: string}>
     */
    public function relations(): array
    {
        $relations = $this->config('relations', []);

        return is_array($relations) ? $relations : [];
    }

    /**
     * Columns the package itself manages, which Claude never writes directly.
     *
     * @return list<string>
     */
    public function managedColumns(): array
    {
        return array_values(array_filter([
            $this->statusColumn(),
            $this->publishedAtColumn(),
            $this->authorColumn(),
        ]));
    }

    public function connectorUrl(): string
    {
        return url(Str::start((string) $this->config('route.path', 'laravel-post-mcp'), '/'));
    }

    public function settingsPageEnabled(): bool
    {
        return (bool) $this->config('ui.enabled', true);
    }

    public function settingsPagePath(): string
    {
        return Str::start(trim((string) $this->config('ui.path', 'laravel-post-mcp/settings'), '/'), '/');
    }

    public function settingsPageUrl(): string
    {
        return url($this->settingsPagePath());
    }

    public function publicUrl(Model $post): ?string
    {
        $route = $this->nullableString('public_url.route');

        if ($route === null || ! app('router')->has($route)) {
            return null;
        }

        try {
            return route($route, $post);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function nullableString(string $key): ?string
    {
        $value = $this->config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
