<?php

namespace Smith89k\PostMcp\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Smith89k\PostMcp\Support\PostInput;

#[IsIdempotent]
class UpdatePost extends PostTool
{
    protected string $name = 'update_post';

    protected string $title = 'Update a post';

    protected string $description = 'Changes some fields of a post. Only send the fields to change; the others stay as they are. The status does not change here: use publish_post, unpublish_post or schedule_post.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $this->toolSchema->id($schema)->required(),
            ...$this->toolSchema->postFields($schema, $this->fields(), PostInput::UPDATE),
        ];
    }

    protected function run(Request $request, Authenticatable $user): Response|ResponseFactory
    {
        $post = $this->findPost($request);
        $input = array_diff_key($request->all(), ['id' => true]);

        if ($input === []) {
            throw ValidationException::withMessages(['fields' => 'Send at least one field to change.']);
        }

        $prepared = app(PostInput::class)->prepare($this->fields(), PostInput::UPDATE, $input, $post);
        $changed = [...$this->changedFields($post, $prepared['attributes']), ...array_keys($prepared['relations'])];

        $post = $this->writer->update($post, $prepared['attributes'], $prepared['relations']);

        $this->audit->record($this->name(), $user, $post, $changed);

        return $this->respond($changed === [] ? 'Nothing changed.' : 'Updated: '.implode(', ', $changed).'.', $post);
    }
}
