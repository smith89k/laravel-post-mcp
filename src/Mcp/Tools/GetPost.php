<?php

namespace Smith89k\PostMcp\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class GetPost extends PostTool
{
    protected string $name = 'get_post';

    protected string $title = 'Get a post';

    protected string $description = 'Gets one post with all its readable fields, its status and its public URL. Look it up by id, or by slug.';

    public function schema(JsonSchema $schema): array
    {
        $properties = ['id' => $this->toolSchema->id($schema)];

        if ($this->postMcp->slugColumn() !== null) {
            $properties['slug'] = $schema->string()->description('The post slug, instead of the id.');
        }

        return $properties;
    }

    protected function run(Request $request, Authenticatable $user): Response|ResponseFactory
    {
        $slug = $request->get('slug');

        if (filled($request->get('id'))) {
            $post = $this->findPost($request);
        } elseif (is_string($slug) && $slug !== '') {
            $post = $this->postMcp->findBySlug($slug);
        } else {
            throw ValidationException::withMessages(['id' => 'Send an id or a slug.']);
        }

        return Response::structured($this->presenter->full($post, $this->fields()));
    }
}
