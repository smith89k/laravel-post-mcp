<?php

namespace Smith89k\PostMcp\Tests\Feature\Tools;

use Illuminate\Support\Carbon;
use Smith89k\PostMcp\Audit\AuditLog;
use Smith89k\PostMcp\Content\MarkdownToHtmlConverter;
use Smith89k\PostMcp\Mcp\PostServer;
use Smith89k\PostMcp\Mcp\Tools\CreatePost;
use Smith89k\PostMcp\Tests\Fixtures\Models\Category;
use Smith89k\PostMcp\Tests\Fixtures\Models\PostState;
use Smith89k\PostMcp\Tests\Fixtures\Models\RichPost;
use Smith89k\PostMcp\Tests\Fixtures\Models\SimplePost;
use Smith89k\PostMcp\Tests\Fixtures\Models\Tag;
use Smith89k\PostMcp\Tests\Fixtures\Models\UnusualPost;
use Smith89k\PostMcp\Tests\TestCase;

class CreatePostTest extends TestCase
{
    public function test_saves_a_draft_unless_publish_is_true(): void
    {
        $editor = $this->editor();

        PostServer::actingAs($editor)
            ->tool(CreatePost::class, ['title' => 'Hello world', 'body' => 'A body that is long enough.'])
            ->assertOk()
            ->assertSee('Saved as a draft');

        $post = RichPost::sole();
        $this->assertSame('draft', $post->status);
        $this->assertNull($post->published_at);
        $this->assertSame('hello-world', $post->slug);
        $this->assertSame($editor->id, $post->user_id);
    }

    public function test_publishes_now_when_publish_is_true(): void
    {
        Carbon::setTestNow('2030-01-01 10:00:00');

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, ['title' => 'Live', 'body' => 'A body that is long enough.', 'publish' => true])
            ->assertOk()
            ->assertSee('Published.');

        $post = RichPost::sole();
        $this->assertSame('published', $post->status);
        $this->assertTrue($post->published_at->equalTo(Carbon::now()));
    }

    public function test_schedules_with_publish_and_a_future_publish_at(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'title' => 'Later',
                'body' => 'A body that is long enough.',
                'publish' => true,
                'publish_at' => now()->addWeek()->toAtomString(),
            ])
            ->assertOk()
            ->assertSee('Scheduled.');

        $this->assertSame('scheduled', RichPost::sole()->status);
    }

    public function test_rejects_publish_at_without_publish(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, ['title' => 'Later', 'body' => 'A body that is long enough.', 'publish_at' => now()->addWeek()->toAtomString()])
            ->assertHasErrors(['publish_at: Set publish: true to publish or schedule the post at publish_at.']);

        $this->assertDatabaseCount('rich_posts', 0);
    }

    public function test_lists_every_missing_required_field(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [])
            ->assertHasErrors([
                'Nothing was changed. Fix these and try again',
                'title: The title field is required.',
                'body: The body field is required.',
            ]);
    }

    public function test_applies_the_site_form_request_rules_and_messages(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, ['title' => 'Short', 'body' => 'Too short'])
            ->assertHasErrors(['body: The body needs at least 20 characters.']);
    }

    public function test_rejects_fields_that_are_not_writable(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'title' => 'Sneaky',
                'body' => 'A body that is long enough.',
                'internal_notes' => 'x',
                'status' => 'published',
                'api_token' => 'x',
            ])
            ->assertHasErrors([
                'internal_notes cannot be changed here.',
                'status cannot be changed here. Changed with publish_post',
                'api_token is not a field of this post.',
            ]);

        $this->assertDatabaseCount('rich_posts', 0);
    }

    public function test_makes_a_unique_slug_that_avoids_reserved_words(): void
    {
        RichPost::query()->create(['title' => 'Feed', 'slug' => 'feed-2', 'body' => 'x']);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, ['title' => 'Feed', 'body' => 'A body that is long enough.'])
            ->assertOk();

        $this->assertSame('feed-3', RichPost::query()->latest('id')->first()->slug);
    }

    public function test_rejects_a_reserved_or_taken_slug(): void
    {
        RichPost::query()->create(['title' => 'Taken', 'slug' => 'taken', 'body' => 'x']);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, ['title' => 'A', 'slug' => 'admin', 'body' => 'A body that is long enough.'])
            ->assertHasErrors(['slug: The selected slug is invalid.']);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, ['title' => 'A', 'slug' => 'taken', 'body' => 'A body that is long enough.'])
            ->assertHasErrors(['slug: The slug has already been taken.']);
    }

    public function test_sets_relations_by_label_or_id_and_creates_new_tags(): void
    {
        $news = Category::query()->create(['name' => 'News']);
        $php = Tag::query()->create(['name' => 'PHP']);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'title' => 'Tagged',
                'body' => 'A body that is long enough.',
                'category' => 'News',
                'tags' => [(string) $php->id, 'Laravel'],
            ])
            ->assertOk()
            ->assertSee('"tags":["PHP","Laravel"]');

        $post = RichPost::sole();
        $this->assertTrue($post->category->is($news));
        $this->assertSame(['PHP', 'Laravel'], $post->tags->pluck('name')->all());
        $this->assertSame('laravel', Tag::query()->firstWhere('name', 'Laravel')->slug);
    }

    public function test_refuses_unknown_values_for_relations_that_cannot_create(): void
    {
        Category::query()->create(['name' => 'News']);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, ['title' => 'A', 'body' => 'A body that is long enough.', 'category' => 'Sport'])
            ->assertHasErrors(['category: "Sport" is not an existing category value. Valid values: News.']);

        $this->assertDatabaseCount('rich_posts', 0);
    }

    public function test_a_new_related_item_that_needs_more_than_a_name_gets_a_readable_error(): void
    {
        config(['post-mcp.relations.author' => ['label' => 'name', 'creatable' => true]]);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, ['title' => 'A', 'body' => 'A body that is long enough.', 'author' => 'Ghost Writer'])
            ->assertHasErrors(['author: Could not create a new author from just a name. Create it on the site first, or use an existing one.']);

        $this->assertDatabaseCount('rich_posts', 0);
    }

    public function test_validates_new_relation_labels_with_the_configured_rules(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, ['title' => 'A', 'body' => 'A body that is long enough.', 'tags' => [str_repeat('x', 31)]])
            ->assertHasErrors(['tags: The new tags name field must not be greater than 30 characters.']);

        $this->assertDatabaseCount('tags', 0);
    }

    public function test_fires_model_events_and_records_an_audit_entry(): void
    {
        $editor = $this->editor();

        PostServer::actingAs($editor)
            ->tool(CreatePost::class, ['title' => 'Audited', 'body' => 'A body that is long enough.'])
            ->assertOk();

        $this->assertGreaterThan(0, RichPost::$savingEvents);

        $entry = AuditLog::sole();
        $this->assertSame('create_post', $entry->tool);
        $this->assertSame((string) RichPost::sole()->id, $entry->post_id);
        $this->assertSame((string) $editor->id, (string) $entry->user_id);
        $this->assertContains('title', $entry->changed_fields);
    }

    public function test_works_with_a_simple_post_without_slug_or_dates(): void
    {
        $this->useSimplePost();

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, ['title' => 'Simple', 'body' => '<p>Hi</p>', 'publish' => true])
            ->assertOk();

        $this->assertSame('published', SimplePost::sole()->status);
    }

    public function test_works_with_unusual_columns_and_an_enum_status(): void
    {
        $this->useUnusualPost();

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'headline' => 'Odd names',
                'markdown_text' => '# Title',
                'publish' => true,
                'publish_at' => now()->addDay()->toAtomString(),
            ])
            ->assertOk();

        $post = UnusualPost::sole();
        $this->assertSame(PostState::Queued, $post->state);
        $this->assertSame('odd-names', $post->permalink);
        $this->assertSame('# Title', $post->markdown_text);
    }

    public function test_converts_markdown_to_html_when_a_converter_is_set(): void
    {
        config([
            'post-mcp.content.input_format' => 'markdown',
            'post-mcp.content.converter' => MarkdownToHtmlConverter::class,
        ]);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, ['title' => 'Converted', 'body' => 'Some **bold** words in a body.'])
            ->assertOk();

        $this->assertSame("<p>Some <strong>bold</strong> words in a body.</p>\n", RichPost::sole()->body);
    }
}
