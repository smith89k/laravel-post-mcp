<?php

namespace Smith89k\PostMcp\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Smith89k\PostMcp\PostMcp;

#[IsDestructive]
class DeletePost extends PostTool
{
    protected string $name = 'delete_post';

    protected string $title = 'Delete a post';

    protected string $description = 'Deletes a post for good. Ask the user before calling this. Prefer unpublish_post to hide a post.';

    public function shouldRegister(PostMcp $postMcp): bool
    {
        return $postMcp->deleteEnabled();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $this->toolSchema->id($schema)->required(),
            'confirm' => $schema->boolean()->description('Must be true. Confirms the user asked for the post to be deleted.')->required(),
        ];
    }

    protected function run(Request $request, Authenticatable $user): Response|ResponseFactory
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Set confirm: true once the user has agreed to delete the post.']);

        $post = $this->findPost($request);
        $title = (string) $post->getAttribute($this->postMcp->titleColumn());

        $this->audit->record($this->name(), $user, $post, [], ['title' => $title]);
        $this->writer->delete($post);

        return Response::structured(['message' => "Deleted \"{$title}\".", 'id' => $post->getKey()]);
    }
}
