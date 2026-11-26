<?php

namespace Smith89k\PostMcp\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Smith89k\PostMcp\Status\StatusManager;
use Smith89k\PostMcp\Support\PostInput;

class CreatePost extends PostTool
{
    protected string $name = 'create_post';

    protected string $title = 'Create a post';

    protected string $description = 'Creates a post. It is saved as a draft unless you pass publish: true. To schedule it, pass publish: true with a future publish_at. Call get_post_fields first to see the fields and their allowed values.';

    public function schema(JsonSchema $schema): array
    {
        $properties = $this->toolSchema->postFields($schema, $this->fields(), PostInput::CREATE);

        $properties['publish'] = $schema->boolean()->description('Publish the post now (or at publish_at). Leave out or false to save a draft.');

        if ($this->postMcp->supportsScheduling()) {
            $properties['publish_at'] = $schema->string()->format('date-time')->description('With publish: true, when the post goes live (ISO 8601). A future date schedules it.');
        }

        return $properties;
    }

    protected function run(Request $request, Authenticatable $user): Response|ResponseFactory
    {
        $options = Validator::make($request->all(), [
            'publish' => ['nullable', 'boolean'],
            'publish_at' => ['nullable', 'date', 'prohibited_unless:publish,true,1'],
        ], [
            'publish_at.prohibited_unless' => 'Set publish: true to publish or schedule the post at publish_at.',
        ])->validate();

        $prepared = app(PostInput::class)->prepare(
            $this->fields(),
            PostInput::CREATE,
            array_diff_key($request->all(), array_flip(['publish', 'publish_at'])),
        );

        $at = filled($options['publish_at'] ?? null) ? Carbon::parse($options['publish_at']) : null;
        $status = match (true) {
            ! ($options['publish'] ?? false) => StatusManager::DRAFT,
            $at !== null && $at->isFuture() => StatusManager::SCHEDULED,
            default => StatusManager::PUBLISHED,
        };

        $attributes = [
            ...$prepared['attributes'],
            ...$this->status->attributesFor($status, $this->postMcp->newModel(), $at),
        ];

        if (($author = $this->postMcp->authorColumn()) !== null) {
            $attributes[$author] = $user->getAuthIdentifier();
        }

        $post = $this->writer->create($attributes, $prepared['relations']);

        $this->audit->record($this->name(), $user, $post, [...array_keys($attributes), ...array_keys($prepared['relations'])], ['status' => $status]);

        return $this->respond(match ($status) {
            StatusManager::DRAFT => 'Saved as a draft. Use publish_post to publish it.',
            StatusManager::SCHEDULED => 'Scheduled.',
            default => 'Published.',
        }, $post);
    }
}
