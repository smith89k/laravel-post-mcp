<?php

namespace Smith89k\PostMcp\Tests\Feature\Tools;

use Smith89k\PostMcp\Audit\AuditLog;
use Smith89k\PostMcp\Mcp\PostServer;
use Smith89k\PostMcp\Mcp\Tools\DeletePost;
use Smith89k\PostMcp\Tests\Fixtures\Models\RichPost;
use Smith89k\PostMcp\Tests\TestCase;

class DeletePostTest extends TestCase
{
    public function test_is_not_offered_by_default(): void
    {
        $post = RichPost::query()->create(['title' => 'A', 'slug' => 'a', 'body' => 'x']);

        PostServer::actingAs($this->editor())->tool(DeletePost::class, ['id' => $post->id, 'confirm' => true])->assertNotRegistered();

        $this->assertModelExists($post);
    }

    public function test_deletes_only_with_confirm_once_enabled(): void
    {
        config(['post-mcp.delete.enabled' => true]);
        $post = RichPost::query()->create(['title' => 'Bye', 'slug' => 'bye', 'body' => 'x']);

        PostServer::actingAs($this->editor())
            ->tool(DeletePost::class, ['id' => $post->id])
            ->assertHasErrors(['confirm: Set confirm: true once the user has agreed to delete the post.']);
        $this->assertModelExists($post);

        PostServer::actingAs($this->editor())
            ->tool(DeletePost::class, ['id' => $post->id, 'confirm' => true])
            ->assertOk()
            ->assertSee('Deleted \"Bye\".');

        $this->assertModelMissing($post);
        $this->assertSame(['title' => 'Bye'], AuditLog::sole()->meta);
    }
}
