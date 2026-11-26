<?php

namespace Smith89k\PostMcp\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Smith89k\PostMcp\Status\StatusManager;

#[IsIdempotent]
class PublishPost extends PostTool
{
    protected string $name = 'publish_post';

    protected string $title = 'Publish a post';

    protected string $description = 'Publishes a post now, so readers can see it. A scheduled post is published straight away.';

    public function schema(JsonSchema $schema): array
    {
        $properties = ['id' => $this->toolSchema->id($schema)->required()];

        if ($this->postMcp->supportsScheduling()) {
            $properties['published_at'] = $schema->string()->format('date-time')->description('Optional past date to show as the publish date (ISO 8601). Leave out for now.');
        }

        return $properties;
    }

    protected function run(Request $request, Authenticatable $user): Response|ResponseFactory
    {
        $validated = $request->validate([
            'published_at' => ['nullable', 'date', 'before_or_equal:now'],
        ], [
            'published_at.before_or_equal' => 'The published_at date cannot be in the future. Use schedule_post for that.',
        ]);

        $post = $this->findPost($request);
        $at = filled($validated['published_at'] ?? null) ? Carbon::parse($validated['published_at']) : null;

        return $this->changeStatus($post, $this->status->attributesFor(StatusManager::PUBLISHED, $post, $at), $user, 'Published.');
    }
}
