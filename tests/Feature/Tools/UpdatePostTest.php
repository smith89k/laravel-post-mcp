<?php

namespace Smith89k\PostMcp\Tests\Feature\Tools;

use Smith89k\PostMcp\Audit\AuditLog;
use Smith89k\PostMcp\Mcp\PostServer;
use Smith89k\PostMcp\Mcp\Tools\UpdatePost;
use Smith89k\PostMcp\Tests\Fixtures\Models\RichPost;
use Smith89k\PostMcp\Tests\Fixtures\Models\Tag;
use Smith89k\PostMcp\Tests\TestCase;

class UpdatePostTest extends TestCase
{
    protected function publishedPost(): RichPost
    {
        return RichPost::query()->create([
            'title' => 'Original title',
            'slug' => 'original-title',
            'body' => 'The original body text.',
            'excerpt' => 'Original excerpt',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    public function test_changes_only_the_fields_sent_and_keeps_the_slug_and_status(): void
    {
        $post = $this->publishedPost();

        PostServer::actingAs($this->editor())
            ->tool(UpdatePost::class, ['id' => $post->id, 'title' => 'New title'])
            ->assertOk()
            ->assertSee('Updated: title.');

        $post->refresh();
        $this->assertSame('New title', $post->title);
        $this->assertSame('original-title', $post->slug);
        $this->assertSame('Original excerpt', $post->excerpt);
        $this->assertSame('published', $post->status);
        $this->assertSame(['title'], AuditLog::sole()->changed_fields);
    }

    public function test_replaces_the_tag_list(): void
    {
        $post = $this->publishedPost();
        $post->tags()->attach(Tag::query()->create(['name' => 'Old'])->id);

        PostServer::actingAs($this->editor())
            ->tool(UpdatePost::class, ['id' => $post->id, 'tags' => ['New']])
            ->assertOk();

        $this->assertSame(['New'], $post->refresh()->tags->pluck('name')->all());
    }

    public function test_requires_at_least_one_field(): void
    {
        $post = $this->publishedPost();

        PostServer::actingAs($this->editor())
            ->tool(UpdatePost::class, ['id' => $post->id])
            ->assertHasErrors(['fields: Send at least one field to change.']);
    }

    public function test_rejects_an_empty_required_field(): void
    {
        $post = $this->publishedPost();

        PostServer::actingAs($this->editor())
            ->tool(UpdatePost::class, ['id' => $post->id, 'title' => ''])
            ->assertHasErrors(['title: The title field is required.']);

        $this->assertSame('Original title', $post->refresh()->title);
    }

    public function test_allows_keeping_its_own_slug(): void
    {
        $post = $this->publishedPost();

        PostServer::actingAs($this->editor())
            ->tool(UpdatePost::class, ['id' => $post->id, 'slug' => 'original-title', 'excerpt' => 'Changed'])
            ->assertOk();
    }

    public function test_reports_an_unknown_post_id(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(UpdatePost::class, ['id' => 999, 'title' => 'x'])
            ->assertHasErrors(['No post with id 999. Use list_posts to find the right id.']);
    }
}
