<?php

namespace Smith89k\PostMcp\Mcp;

use Laravel\Mcp\Server;
use Smith89k\PostMcp\Mcp\Tools\CreatePost;
use Smith89k\PostMcp\Mcp\Tools\DeletePost;
use Smith89k\PostMcp\Mcp\Tools\GetPost;
use Smith89k\PostMcp\Mcp\Tools\GetPostFields;
use Smith89k\PostMcp\Mcp\Tools\ListPosts;
use Smith89k\PostMcp\Mcp\Tools\PublishPost;
use Smith89k\PostMcp\Mcp\Tools\SchedulePost;
use Smith89k\PostMcp\Mcp\Tools\UnpublishPost;
use Smith89k\PostMcp\Mcp\Tools\UpdatePost;
use Smith89k\PostMcp\Mcp\Tools\UploadImage;
use Smith89k\PostMcp\PostMcp;

/**
 * The MCP server Claude connects to. It only offers the post tools.
 */
class PostServer extends Server
{
    protected string $version = '0.1.0';

    /**
     * @var array<int, class-string<Server\Tool>>
     */
    protected array $tools = [
        GetPostFields::class,
        ListPosts::class,
        GetPost::class,
        CreatePost::class,
        UpdatePost::class,
        PublishPost::class,
        UnpublishPost::class,
        SchedulePost::class,
        UploadImage::class,
        DeletePost::class,
    ];

    protected function boot(): void
    {
        $postMcp = app(PostMcp::class);

        $this->name = $postMcp->serverName();

        $custom = $postMcp->config('server.instructions');

        $this->instructions = is_string($custom) && $custom !== '' ? $custom : <<<'MARKDOWN'
            Manage the blog posts of this website.

            - Call get_post_fields first. It lists the fields, which are required, the allowed values and the valid tags, categories or other related values.
            - Use list_posts and get_post to find and read posts. Read a post before you change it.
            - create_post saves a draft unless you pass publish: true. Only publish when the user asks for it.
            - update_post changes only the fields you send. Status changes go through publish_post, unpublish_post and schedule_post.
            - If a tool answers with validation errors, fix the listed fields and call it again.
            - upload_image returns a URL and a snippet to place in the body.
        MARKDOWN;
    }
}
