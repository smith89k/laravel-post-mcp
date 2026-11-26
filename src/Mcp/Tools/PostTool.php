<?php

namespace Smith89k\PostMcp\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Smith89k\PostMcp\Audit\AuditLogger;
use Smith89k\PostMcp\Contracts\PostWriter;
use Smith89k\PostMcp\Exceptions\PostMcpException;
use Smith89k\PostMcp\Fields\FieldMap;
use Smith89k\PostMcp\Fields\FieldResolver;
use Smith89k\PostMcp\Mcp\ToolSchema;
use Smith89k\PostMcp\PostMcp;
use Smith89k\PostMcp\Status\StatusManager;
use Smith89k\PostMcp\Support\PostPresenter;

/**
 * Checks the gate on every call and turns problems into messages Claude can act on.
 */
abstract class PostTool extends Tool
{
    public function __construct(
        protected PostMcp $postMcp,
        protected FieldResolver $fieldResolver,
        protected StatusManager $status,
        protected PostPresenter $presenter,
        protected AuditLogger $audit,
        protected PostWriter $writer,
        protected ToolSchema $toolSchema,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            return $this->run($request, $this->authorize($request));
        } catch (ValidationException $exception) {
            return Response::error($this->validationMessage($exception));
        } catch (PostMcpException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    abstract protected function run(Request $request, Authenticatable $user): Response|ResponseFactory;

    protected function authorize(Request $request): Authenticatable
    {
        $user = $request->user();

        if ($user === null) {
            throw new PostMcpException('You are not signed in. Reconnect the connector and sign in again.');
        }

        if (! Gate::forUser($user)->allows($this->postMcp->gate())) {
            throw new PostMcpException("This account is not allowed to manage posts. The site owner must grant the \"{$this->postMcp->gate()}\" ability.");
        }

        return $user;
    }

    protected function fields(): FieldMap
    {
        return $this->fieldResolver->resolve();
    }

    protected function findPost(Request $request): Model
    {
        $id = $request->get('id');

        if ($id === null || $id === '') {
            throw ValidationException::withMessages(['id' => 'The id is required. Use list_posts to find it.']);
        }

        if (! is_int($id) && ! is_string($id)) {
            throw ValidationException::withMessages(['id' => 'The id must be a number or a string.']);
        }

        return $this->postMcp->find($id);
    }

    /**
     * Saves status attributes through the writer and records it.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function changeStatus(Model $post, array $attributes, Authenticatable $user, string $message): ResponseFactory
    {
        $changed = $this->changedFields($post, $attributes);
        $post = $this->writer->update($post, $attributes, []);

        $this->audit->record($this->name(), $user, $post, $changed, ['status' => $this->status->statusOf($post)]);

        return $this->respond($message, $post);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    protected function changedFields(Model $post, array $attributes): array
    {
        $probe = clone $post;
        $probe->forceFill($attributes);

        return array_keys($probe->getDirty());
    }

    protected function respond(string $message, Model $post): ResponseFactory
    {
        return Response::structured([
            'message' => $message,
            'post' => $this->presenter->full($post, $this->fields()),
        ]);
    }

    protected function validationMessage(ValidationException $exception): string
    {
        $lines = [];

        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $lines[] = "- {$field}: {$message}";
            }
        }

        return "Nothing was changed. Fix these and try again (get_post_fields describes every field):\n".implode("\n", $lines);
    }
}
