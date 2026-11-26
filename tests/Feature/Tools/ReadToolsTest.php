<?php

namespace Smith89k\PostMcp\Tests\Feature\Tools;

use Smith89k\PostMcp\Mcp\PostServer;
use Smith89k\PostMcp\Mcp\Tools\GetPost;
use Smith89k\PostMcp\Mcp\Tools\GetPostFields;
use Smith89k\PostMcp\Mcp\Tools\ListPosts;
use Smith89k\PostMcp\Tests\Fixtures\Models\Category;
use Smith89k\PostMcp\Tests\Fixtures\Models\RichPost;
use Smith89k\PostMcp\Tests\Fixtures\Models\Tag;
use Smith89k\PostMcp\Tests\Fixtures\Models\UnusualPost;
use Smith89k\PostMcp\Tests\TestCase;

/**
 * get_post_fields, list_posts and get_post.
 */
class ReadToolsTest extends TestCase
{
    protected function seedPosts(): void
    {
        $php = Tag::query()->create(['name' => 'PHP']);

        RichPost::query()->create(['title' => 'Draft about cats', 'slug' => 'cats', 'body' => 'Cats', 'status' => 'draft']);
        RichPost::query()->create(['title' => 'Live about PHP', 'slug' => 'php', 'body' => 'PHP', 'status' => 'published', 'published_at' => '2025-06-01 10:00:00'])
            ->tags()->attach($php->id);
        RichPost::query()->create(['title' => 'Old news', 'slug' => 'old', 'body' => 'Old', 'status' => 'published', 'published_at' => '2020-01-01 10:00:00']);
        RichPost::query()->create(['title' => 'Coming soon', 'slug' => 'soon', 'body' => 'Soon', 'status' => 'scheduled', 'published_at' => now()->addMonth()]);
    }

    public function test_get_post_fields_lists_fields_statuses_and_live_relation_values(): void
    {
        Category::query()->create(['name' => 'News']);
        Tag::query()->create(['name' => 'PHP']);

        PostServer::actingAs($this->editor())
            ->tool(GetPostFields::class)
            ->assertOk()
            ->assertStructuredContentPath('statuses', ['draft', 'published', 'scheduled'])
            ->assertStructuredContentPath('slug.reserved', ['admin', 'feed'])
            ->assertSee(['"valid_values":["News"]', '"valid_values":["PHP"]', '"name":"seo_title"'])
            ->assertDontSee(['api_token', 'internal_notes":{"writable":true']);
    }

    public function test_list_posts_filters_by_status(): void
    {
        $this->seedPosts();

        PostServer::actingAs($this->editor())
            ->tool(ListPosts::class, ['status' => 'scheduled'])
            ->assertOk()
            ->assertStructuredContentPath('total', 1)
            ->assertStructuredContentPath('posts.0.title', 'Coming soon');

        PostServer::actingAs($this->editor())
            ->tool(ListPosts::class, ['status' => 'published'])
            ->assertStructuredContentPath('total', 2)
            ->assertStructuredContentPath('posts.0.title', 'Live about PHP');
    }

    public function test_list_posts_filters_by_search_relation_and_date_range(): void
    {
        $this->seedPosts();

        PostServer::actingAs($this->editor())
            ->tool(ListPosts::class, ['search' => 'cats'])
            ->assertStructuredContentPath('total', 1);

        PostServer::actingAs($this->editor())
            ->tool(ListPosts::class, ['tags' => 'PHP'])
            ->assertStructuredContentPath('total', 1)
            ->assertStructuredContentPath('posts.0.tags', ['PHP']);

        PostServer::actingAs($this->editor())
            ->tool(ListPosts::class, ['from' => '2021-01-01', 'to' => '2026-01-01'])
            ->assertStructuredContentPath('total', 1)
            ->assertStructuredContentPath('posts.0.slug', 'php');
    }

    public function test_list_posts_paginates(): void
    {
        $this->seedPosts();

        PostServer::actingAs($this->editor())
            ->tool(ListPosts::class, ['per_page' => 3, 'page' => 2])
            ->assertStructuredContentPath('total', 4)
            ->assertStructuredContentPath('last_page', 2)
            ->assertStructuredContentCount(1, 'posts');
    }

    public function test_list_posts_rejects_too_many_per_page(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(ListPosts::class, ['per_page' => 500])
            ->assertHasErrors(['per_page']);
    }

    public function test_get_post_by_id_or_slug_returns_readable_fields_only(): void
    {
        $this->seedPosts();
        RichPost::query()->where('slug', 'php')->update(['api_token' => 'secret-value']);

        PostServer::actingAs($this->editor())
            ->tool(GetPost::class, ['slug' => 'php'])
            ->assertOk()
            ->assertStructuredContentPath('title', 'Live about PHP')
            ->assertStructuredContentPath('status', 'published')
            ->assertStructuredContentPath('url', url('/blog/2'))
            ->assertStructuredContentPath('tags', ['PHP'])
            ->assertDontSee('secret-value');

        PostServer::actingAs($this->editor())
            ->tool(GetPost::class, ['id' => RichPost::query()->firstWhere('slug', 'cats')->id])
            ->assertStructuredContentPath('slug', 'cats');
    }

    public function test_get_post_reports_a_missing_slug(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(GetPost::class, ['slug' => 'nope'])
            ->assertHasErrors(['No post with slug "nope". Use list_posts to search for it.']);
    }

    public function test_get_post_works_with_a_custom_primary_key(): void
    {
        $this->useUnusualPost();
        $post = UnusualPost::query()->create(['headline' => 'Odd', 'permalink' => 'odd', 'markdown_text' => 'x', 'secret_key' => 'hush']);

        PostServer::actingAs($this->editor())
            ->tool(GetPost::class, ['id' => $post->article_id])
            ->assertStructuredContentPath('id', $post->article_id)
            ->assertStructuredContentPath('headline', 'Odd')
            ->assertStructuredContentPath('status', 'draft')
            ->assertDontSee('hush');
    }
}
