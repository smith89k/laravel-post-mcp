<?php

namespace Smith89k\PostMcp\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Smith89k\PostMcp\Media\MediaManager;
use Smith89k\PostMcp\Support\RelationResolver;
use Smith89k\PostMcp\Support\SlugGenerator;

#[IsReadOnly]
#[IsIdempotent]
class GetPostFields extends PostTool
{
    protected string $name = 'get_post_fields';

    protected string $title = 'Get post fields';

    protected string $description = 'Describes the fields of a post on this site: types, which are required, allowed values, the valid values of relations such as tags or categories (live from the database), the statuses, the slug rules and the content format. Call this first.';

    protected function run(Request $request, Authenticatable $user): Response|ResponseFactory
    {
        $map = $this->fields();
        $relations = app(RelationResolver::class);
        $media = app(MediaManager::class);

        $fields = [];

        foreach ($map->readable() as $name => $field) {
            $entry = [
                'name' => $name,
                'label' => $field->label,
                'type' => $field->isRelation() ? ($field->isMultipleRelation() ? 'list of '.$field->relation['label'] : $field->relation['label']) : $field->type,
                'description' => $field->description,
                'writable' => $field->writable,
                'required' => $field->required,
            ];

            if ($field->enum !== null && $field->enum !== []) {
                $entry['allowed_values'] = $field->enum;
            }

            if ($field->maxLength !== null) {
                $entry['max_length'] = $field->maxLength;
            }

            if ($field->default !== null) {
                $entry['default'] = $field->default;
            }

            if ($field->notes !== null) {
                $entry['notes'] = $field->notes;
            }

            if ($field->isRelation()) {
                $entry['creatable'] = (bool) ($field->relation['creatable'] ?? false);
                $entry['valid_values'] = $relations->options($field);

                if ($field->relationDetails() !== []) {
                    $entry['details'] = array_map(fn (string $name, array $detail): array => [
                        'name' => $name,
                        'type' => match (true) {
                            $detail['type'] === 'image' => 'image URL',
                            $detail['each'] !== [] => 'list of {'.implode(', ', array_keys($detail['each'])).'}',
                            default => $detail['type'],
                        },
                        'description' => $detail['description'],
                        'adds_to_existing' => $detail['grow'],
                    ], array_keys($field->relationDetails()), $field->relationDetails());
                }
            }

            $fields[] = $entry;
        }

        return Response::structured([
            'post_type' => class_basename($this->postMcp->modelClass()),
            'content' => [
                'field' => $this->postMcp->contentColumn(),
                'format' => $this->postMcp->contentInputFormat(),
            ],
            'statuses' => $this->status->statuses(),
            'scheduling' => $this->postMcp->supportsScheduling(),
            'slug' => $this->postMcp->slugColumn() === null ? null : [
                'field' => $this->postMcp->slugColumn(),
                'strategy' => $this->postMcp->slugStrategy(),
                'reserved' => app(SlugGenerator::class)->reserved(),
            ],
            'fields' => $fields,
            'images' => $media->enabled() ? [
                'driver' => $this->postMcp->mediaDriver(),
                'max_kb' => (int) $this->postMcp->config('media.max_kb'),
                'types' => array_values((array) $this->postMcp->config('media.mimes')),
                'featured_image_field' => $this->postMcp->config('media.attach_to'),
            ] : null,
            'delete_enabled' => $this->postMcp->deleteEnabled(),
            'notes' => [
                'New posts are saved as drafts unless create_post gets publish: true.',
                'Dates use ISO 8601, for example 2030-01-31T09:00:00+00:00.',
            ],
        ]);
    }
}
