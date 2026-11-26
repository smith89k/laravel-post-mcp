<?php

namespace Smith89k\PostMcp\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Smith89k\PostMcp\Mcp\PostServer;
use Smith89k\PostMcp\Mcp\Tools\CreatePost;
use Smith89k\PostMcp\Mcp\Tools\GetPost;
use Smith89k\PostMcp\Mcp\Tools\GetPostFields;
use Smith89k\PostMcp\Mcp\Tools\ListPosts;
use Smith89k\PostMcp\Mcp\Tools\PublishPost;
use Smith89k\PostMcp\Mcp\Tools\SchedulePost;
use Smith89k\PostMcp\Mcp\Tools\UnpublishPost;
use Smith89k\PostMcp\Mcp\Tools\UpdatePost;
use Smith89k\PostMcp\Mcp\Tools\UploadImage;
use Smith89k\PostMcp\Tests\Fixtures\Models\RichPost;
use Smith89k\PostMcp\Tests\TestCase;

class AuthorizationTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: array<string, mixed>}>
     */
    public static function tools(): array
    {
        return [
            'get_post_fields' => [GetPostFields::class, []],
            'list_posts' => [ListPosts::class, []],
            'get_post' => [GetPost::class, ['id' => 1]],
            'create_post' => [CreatePost::class, ['title' => 'x', 'body' => 'A body that is long enough.']],
            'update_post' => [UpdatePost::class, ['id' => 1, 'title' => 'Changed']],
            'publish_post' => [PublishPost::class, ['id' => 1]],
            'unpublish_post' => [UnpublishPost::class, ['id' => 1]],
            'schedule_post' => [SchedulePost::class, ['id' => 1, 'publish_at' => '2099-01-01T00:00:00+00:00']],
            'upload_image' => [UploadImage::class, ['base64' => 'aGVsbG8=']],
        ];
    }

    /**
     * @param  class-string  $tool
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('tools')]
    public function test_users_the_gate_denies_cannot_use_any_tool(string $tool, array $arguments): void
    {
        RichPost::query()->create(['title' => 'Original', 'slug' => 'original', 'body' => 'x']);

        PostServer::actingAs($this->stranger())
            ->tool($tool, $arguments)
            ->assertHasErrors(['This account is not allowed to manage posts. The site owner must grant the "use-post-mcp" ability.']);

        $this->assertSame('Original', RichPost::sole()->title);
        $this->assertSame('draft', RichPost::sole()->status);
    }

    public function test_everyone_is_denied_while_the_gate_is_not_defined(): void
    {
        config(['post-mcp.auth.gate' => 'undefined-ability']);
        $this->assertFalse(Gate::has('undefined-ability'));

        PostServer::actingAs($this->editor())
            ->tool(ListPosts::class)
            ->assertHasErrors(['not allowed to manage posts']);
    }

    public function test_guests_are_told_to_sign_in(): void
    {
        PostServer::tool(ListPosts::class)->assertHasErrors(['You are not signed in.']);
    }
}
