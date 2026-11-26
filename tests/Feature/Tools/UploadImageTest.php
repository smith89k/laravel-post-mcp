<?php

namespace Smith89k\PostMcp\Tests\Feature\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Smith89k\PostMcp\Mcp\PostServer;
use Smith89k\PostMcp\Mcp\Tools\UploadImage;
use Smith89k\PostMcp\Media\HostResolver;
use Smith89k\PostMcp\Tests\Fixtures\Models\RichPost;
use Smith89k\PostMcp\Tests\TestCase;

class UploadImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_stores_base64_on_the_configured_disk_and_returns_snippets(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(UploadImage::class, ['base64' => 'data:image/png;base64,'.self::PNG, 'filename' => 'My Diagram.png', 'alt' => 'A diagram'])
            ->assertOk()
            ->assertSee(['![A diagram](', '<img src=']);

        $files = Storage::disk('public')->files('blog');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('#^blog/my-diagram-[a-z0-9]{8}\.png$#', $files[0]);
    }

    public function test_downloads_from_a_public_url(): void
    {
        $this->resolveHostsTo(['93.184.216.34']);
        Http::fake(['images.example.com/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);

        PostServer::actingAs($this->editor())
            ->tool(UploadImage::class, ['url' => 'https://images.example.com/photo.png'])
            ->assertOk();

        $this->assertCount(1, Storage::disk('public')->files('blog'));
    }

    public function test_refuses_urls_that_point_at_private_addresses(): void
    {
        $this->resolveHostsTo(['169.254.169.254']);
        Http::fake();

        PostServer::actingAs($this->editor())
            ->tool(UploadImage::class, ['url' => 'http://metadata.internal/latest'])
            ->assertHasErrors(['Images can only be fetched from public addresses.']);

        Http::assertNothingSent();
    }

    public function test_refuses_a_redirect_to_a_private_address(): void
    {
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'cdn.example.com' ? ['93.184.216.34'] : ['10.0.0.5'];
            }
        });
        Http::fake(['cdn.example.com/*' => Http::response('', 302, ['Location' => 'http://intranet.local/secret.png'])]);

        PostServer::actingAs($this->editor())
            ->tool(UploadImage::class, ['url' => 'https://cdn.example.com/a.png'])
            ->assertHasErrors(['Images can only be fetched from public addresses.']);
    }

    public function test_checks_the_real_file_type(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(UploadImage::class, ['base64' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'filename' => 'x.png'])
            ->assertHasErrors(['This file is image/svg+xml. Allowed image types: jpg, png, gif, webp.']);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_refuses_files_over_the_size_limit(): void
    {
        config(['post-mcp.media.max_kb' => 0]);

        PostServer::actingAs($this->editor())
            ->tool(UploadImage::class, ['base64' => self::PNG])
            ->assertHasErrors(['The image is larger than 0 KB.']);
    }

    public function test_needs_exactly_one_source(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(UploadImage::class, [])
            ->assertHasErrors(['url: Send the image as url or base64.']);
    }

    public function test_sets_the_featured_image_through_the_writer(): void
    {
        $post = RichPost::query()->create(['title' => 'A', 'slug' => 'a', 'body' => 'x']);
        $saves = RichPost::$savingEvents;

        PostServer::actingAs($this->editor())
            ->tool(UploadImage::class, ['base64' => self::PNG, 'filename' => 'cover.png', 'post_id' => $post->id, 'set_as_featured' => true])
            ->assertOk()
            ->assertSee('Uploaded and set as the featured image.');

        $this->assertStringStartsWith('blog/cover-', $post->refresh()->featured_image);
        $this->assertGreaterThan($saves, RichPost::$savingEvents);
    }

    public function test_is_not_offered_when_media_is_off(): void
    {
        config(['post-mcp.media.driver' => 'none']);

        PostServer::actingAs($this->editor())->tool(UploadImage::class, ['base64' => self::PNG])->assertNotRegistered();
    }
}
