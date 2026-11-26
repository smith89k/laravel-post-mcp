<?php

namespace Smith89k\PostMcp\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Smith89k\PostMcp\Status\StatusManager;

#[IsIdempotent]
class UnpublishPost extends PostTool
{
    protected string $name = 'unpublish_post';

    protected string $title = 'Unpublish a post';

    protected string $description = 'Turns a published or scheduled post back into a draft, so readers no longer see it.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $this->toolSchema->id($schema)->required()];
    }

    protected function run(Request $request, Authenticatable $user): Response|ResponseFactory
    {
        $post = $this->findPost($request);

        return $this->changeStatus($post, $this->status->attributesFor(StatusManager::DRAFT, $post), $user, 'Unpublished: it is a draft now.');
    }
}
