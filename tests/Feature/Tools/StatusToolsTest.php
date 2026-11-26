<?php

namespace Smith89k\PostMcp\Tests\Feature\Tools;

use Illuminate\Support\Carbon;
use Smith89k\PostMcp\Mcp\PostServer;
use Smith89k\PostMcp\Mcp\Tools\PublishPost;
use Smith89k\PostMcp\Mcp\Tools\SchedulePost;
use Smith89k\PostMcp\Mcp\Tools\UnpublishPost;
use Smith89k\PostMcp\Tests\Fixtures\Models\PostState;
use Smith89k\PostMcp\Tests\Fixtures\Models\RichPost;
use Smith89k\PostMcp\Tests\Fixtures\Models\SimplePost;
use Smith89k\PostMcp\Tests\Fixtures\Models\UnusualPost;
use Smith89k\PostMcp\Tests\TestCase;

/**
 * publish_post, unpublish_post and schedule_post share the status logic.
 */
class StatusToolsTest extends TestCase
{
    protected function draft(): RichPost
    {
        return RichPost::query()->create(['title' => 'Draft', 'slug' => 'draft', 'body' => 'Body text']);
    }

    public function test_publish_sets_the_status_and_date(): void
    {
        Carbon::setTestNow('2030-05-01 12:00:00');
        $post = $this->draft();

        PostServer::actingAs($this->editor())->tool(PublishPost::class, ['id' => $post->id])->assertOk();

        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertTrue($post->published_at->equalTo(Carbon::now()));
    }

    public function test_publish_turns_a_scheduled_post_live_now(): void
    {
        Carbon::setTestNow('2030-05-01 12:00:00');
        $post = $this->draft();
        $post->update(['status' => 'scheduled', 'published_at' => now()->addWeek()]);

        PostServer::actingAs($this->editor())->tool(PublishPost::class, ['id' => $post->id])->assertOk();

        $this->assertTrue($post->refresh()->published_at->equalTo(Carbon::now()));
    }

    public function test_publish_refuses_a_future_published_at(): void
    {
        $post = $this->draft();

        PostServer::actingAs($this->editor())
            ->tool(PublishPost::class, ['id' => $post->id, 'published_at' => now()->addDay()->toAtomString()])
            ->assertHasErrors(['published_at: The published_at date cannot be in the future. Use schedule_post for that.']);
    }

    public function test_unpublish_makes_it_a_draft_and_keeps_the_date(): void
    {
        $post = $this->draft();
        $post->update(['status' => 'published', 'published_at' => '2029-01-01 00:00:00']);

        PostServer::actingAs($this->editor())->tool(UnpublishPost::class, ['id' => $post->id])->assertOk()->assertSee('"status":"draft"');

        $post->refresh();
        $this->assertSame('draft', $post->status);
        $this->assertSame('2029-01-01', $post->published_at->toDateString());
    }

    public function test_schedule_requires_a_future_date(): void
    {
        $post = $this->draft();

        PostServer::actingAs($this->editor())
            ->tool(SchedulePost::class, ['id' => $post->id, 'publish_at' => now()->subDay()->toAtomString()])
            ->assertHasErrors(['publish_at: The publish_at date must be in the future. Use publish_post to publish now.']);

        PostServer::actingAs($this->editor())
            ->tool(SchedulePost::class, ['id' => $post->id, 'publish_at' => '2099-01-01T09:00:00+00:00'])
            ->assertOk()
            ->assertSee('"status":"scheduled"');

        $this->assertSame('scheduled', $post->refresh()->status);
    }

    public function test_schedule_is_not_offered_without_a_publish_date_column(): void
    {
        $this->useSimplePost();

        PostServer::actingAs($this->editor())->tool(SchedulePost::class, ['id' => 1])->assertNotRegistered();
    }

    public function test_status_changes_work_with_an_enum_status_column(): void
    {
        $this->useUnusualPost();
        $post = UnusualPost::query()->create(['headline' => 'A', 'permalink' => 'a', 'markdown_text' => 'x']);

        PostServer::actingAs($this->editor())->tool(PublishPost::class, ['id' => $post->article_id])->assertOk();
        $this->assertSame(PostState::Live, $post->refresh()->state);

        PostServer::actingAs($this->editor())->tool(UnpublishPost::class, ['id' => $post->article_id])->assertOk();
        $this->assertSame(PostState::Hidden, $post->refresh()->state);
    }

    public function test_status_changes_work_with_a_simple_status_column(): void
    {
        $this->useSimplePost();
        $post = SimplePost::query()->create(['title' => 'A', 'body' => 'x']);

        PostServer::actingAs($this->editor())->tool(PublishPost::class, ['id' => $post->id])->assertOk();

        $this->assertSame('published', $post->refresh()->status);
    }

    public function test_status_changes_work_with_a_boolean_flag(): void
    {
        $this->useSimplePost();
        config(['post-mcp.status' => ['mode' => 'boolean', 'column' => 'is_live', 'values' => ['draft' => false, 'published' => true], 'published_at' => null]]);
        $post = SimplePost::query()->create(['title' => 'A', 'body' => 'x']);

        PostServer::actingAs($this->editor())->tool(PublishPost::class, ['id' => $post->id])->assertOk()->assertSee('"status":"published"');
        $this->assertTrue((bool) $post->refresh()->is_live);

        PostServer::actingAs($this->editor())->tool(UnpublishPost::class, ['id' => $post->id])->assertOk()->assertSee('"status":"draft"');
        $this->assertFalse((bool) $post->refresh()->is_live);
    }
}
