<?php

namespace Smith89k\PostMcp\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Smith89k\PostMcp\PostMcp;
use Smith89k\PostMcp\Status\StatusManager;

#[IsIdempotent]
class SchedulePost extends PostTool
{
    protected string $name = 'schedule_post';

    protected string $title = 'Schedule a post';

    protected string $description = 'Schedules a post to go live at a future date and time.';

    public function shouldRegister(PostMcp $postMcp): bool
    {
        return $postMcp->supportsScheduling();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $this->toolSchema->id($schema)->required(),
            'publish_at' => $schema->string()->format('date-time')->description('When the post goes live, in the future (ISO 8601, e.g. 2030-01-31T09:00:00+00:00).')->required(),
        ];
    }

    protected function run(Request $request, Authenticatable $user): Response|ResponseFactory
    {
        $validated = $request->validate([
            'publish_at' => ['required', 'date', 'after:now'],
        ], [
            'publish_at.after' => 'The publish_at date must be in the future. Use publish_post to publish now.',
        ]);

        $post = $this->findPost($request);
        $at = Carbon::parse($validated['publish_at']);

        return $this->changeStatus($post, $this->status->attributesFor(StatusManager::SCHEDULED, $post, $at), $user, 'Scheduled for '.$at->toAtomString().'.');
    }
}
