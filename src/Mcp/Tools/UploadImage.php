<?php

namespace Smith89k\PostMcp\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Smith89k\PostMcp\Media\ImageFetcher;
use Smith89k\PostMcp\Media\MediaManager;
use Smith89k\PostMcp\PostMcp;

#[IsOpenWorld]
class UploadImage extends PostTool
{
    protected string $name = 'upload_image';

    protected string $title = 'Upload an image';

    protected string $description = 'Uploads an image from a public URL or from base64 data and returns its URL with ready-to-paste Markdown and HTML for the post body.';

    public function shouldRegister(MediaManager $media): bool
    {
        return $media->enabled();
    }

    public function schema(JsonSchema $schema): array
    {
        $properties = [
            'url' => $schema->string()->format('uri')->description('A public http(s) URL of the image. Send this or base64.'),
            'base64' => $schema->string()->description('The image file as base64 or a data: URI. Send this or url.'),
            'filename' => $schema->string()->description('A file name for the image, e.g. "diagram.png".'),
            'alt' => $schema->string()->max(250)->description('Alternative text describing the image.'),
            'post_id' => $this->toolSchema->id($schema, 'The post the image belongs to.'.($this->featuredField() ? ' With set_as_featured, it becomes its featured image.' : '')),
        ];

        if ($this->featuredField() !== null) {
            $properties['set_as_featured'] = $schema->boolean()->description('Make it the featured image of post_id.');
        }

        return $properties;
    }

    protected function run(Request $request, Authenticatable $user): Response|ResponseFactory
    {
        $validated = $request->validate([
            'url' => ['nullable', 'required_without:base64', 'prohibits:base64', 'string', 'url:http,https', 'max:2048'],
            'base64' => ['nullable', 'required_without:url', 'string'],
            'filename' => ['nullable', 'string', 'max:200'],
            'alt' => ['nullable', 'string', 'max:250'],
            'post_id' => ['nullable'],
            'set_as_featured' => ['nullable', 'boolean'],
        ], [
            'url.required_without' => 'Send the image as url or base64.',
            'base64.required_without' => 'Send the image as url or base64.',
            'url.prohibits' => 'Send either url or base64, not both.',
        ]);

        $post = filled($validated['post_id'] ?? null) ? $this->postMcp->find($validated['post_id']) : null;

        if (($validated['set_as_featured'] ?? false) && $post === null) {
            throw ValidationException::withMessages(['post_id' => 'Send post_id to set a featured image.']);
        }
        $fetcher = app(ImageFetcher::class);
        $image = filled($validated['url'] ?? null)
            ? $fetcher->fromUrl($validated['url'], $validated['filename'] ?? null, $validated['alt'] ?? null)
            : $fetcher->fromBase64((string) $validated['base64'], $validated['filename'] ?? null, $validated['alt'] ?? null);

        $stored = app(MediaManager::class)->driver()->store($image, $post);
        $featured = false;

        if ($post !== null && ($validated['set_as_featured'] ?? false) && $this->postMcp->mediaDriver() !== 'medialibrary') {
            $this->attach($post, (string) $this->featuredField(), $this->postMcp->config('media.attach_value', 'path') === 'url' ? $stored->url : (string) $stored->path);
            $featured = true;
        }

        $this->audit->record($this->name(), $user, $post, $featured ? [(string) $this->featuredField()] : [], [
            'url' => $stored->url,
            'size' => $image->size(),
            'mime' => $image->mime,
        ]);

        return Response::structured([
            'message' => $featured ? 'Uploaded and set as the featured image.' : 'Uploaded. Put the markdown or html snippet in the post body where the image belongs.',
            ...$stored->toArray(),
        ]);
    }

    protected function attach(Model $post, string $column, string $value): void
    {
        $this->writer->update($post, [$column => $value], []);
    }

    protected function featuredField(): ?string
    {
        $field = $this->postMcp->config('media.attach_to');

        return is_string($field) && $field !== '' && app(PostMcp::class)->mediaDriver() !== 'medialibrary' ? $field : null;
    }
}
