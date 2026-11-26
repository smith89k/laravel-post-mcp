<?php

namespace Smith89k\PostMcp\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Smith89k\PostMcp\Contracts\PostWriter;
use Smith89k\PostMcp\Mcp\PostServer;
use Smith89k\PostMcp\Mcp\Tools\CreatePost;
use Smith89k\PostMcp\Mcp\Tools\PublishPost;
use Smith89k\PostMcp\Tests\Fixtures\Models\RichPost;
use Smith89k\PostMcp\Tests\TestCase;
use Smith89k\PostMcp\Writing\EloquentPostWriter;

/**
 * Saving goes through a replaceable writer, and each change is logged.
 */
class WriterTest extends TestCase
{
    public function test_a_custom_writer_from_config_saves_the_post(): void
    {
        config(['post-mcp.writer' => RecordingWriter::class]);
        $this->app->forgetInstance(PostWriter::class);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, ['title' => 'Via service', 'body' => 'A body that is long enough.'])
            ->assertOk();

        $this->assertSame(['create'], RecordingWriter::$calls);
        $this->assertSame('Via service (saved by service)', RichPost::sole()->title);
    }

    public function test_changes_are_written_to_the_log_channel_when_set(): void
    {
        config(['post-mcp.audit.log_channel' => 'stack']);
        Log::shouldReceive('channel')->with('stack')->once()->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(fn (string $message, array $context) => $message === 'post-mcp: publish_post' && $context['changed_fields'] === ['status', 'published_at']);

        $post = RichPost::query()->create(['title' => 'A', 'slug' => 'a', 'body' => 'x']);

        PostServer::actingAs($this->editor())->tool(PublishPost::class, ['id' => $post->id])->assertOk();
    }

    public function test_audit_can_be_turned_off(): void
    {
        config(['post-mcp.audit.enabled' => false]);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, ['title' => 'Quiet', 'body' => 'A body that is long enough.'])
            ->assertOk();

        $this->assertDatabaseCount('post_mcp_audit_logs', 0);
    }
}

class RecordingWriter extends EloquentPostWriter
{
    /**
     * @var list<string>
     */
    public static array $calls = [];

    public function create(array $attributes, array $relations): Model
    {
        static::$calls[] = 'create';
        $attributes['title'] .= ' (saved by service)';

        return parent::create($attributes, $relations);
    }
}
