<?php

namespace Smith89k\PostMcp\Tests\Feature\Install;

use Illuminate\Support\Facades\File;
use Smith89k\PostMcp\Tests\Fixtures\Models\UnusualPost;
use Smith89k\PostMcp\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        File::delete(config_path('post-mcp.php'));
        File::delete(File::glob(database_path('migrations/*_create_post_mcp_*.php')));

        parent::tearDown();
    }

    public function test_detects_the_model_and_writes_a_matching_config(): void
    {
        $this->artisan('post-mcp:install', ['--model' => UnusualPost::class, '--auth' => 'token', '--no-interaction' => true])
            ->expectsOutputToContain('headline')
            ->expectsOutputToContain('Wrote config/post-mcp.php')
            ->expectsOutputToContain('Connector URL: http://localhost/laravel-post-mcp')
            ->expectsOutputToContain('Settings page: http://localhost/laravel-post-mcp/settings')
            ->assertSuccessful();

        $config = require config_path('post-mcp.php');

        $this->assertSame(UnusualPost::class, $config['model']);
        $this->assertSame('headline', $config['title_column']);
        $this->assertSame('markdown_text', $config['content']['column']);
        $this->assertSame('markdown', $config['content']['format']);
        $this->assertSame('permalink', $config['slug']['column']);
        $this->assertSame(['mode' => 'column', 'column' => 'state', 'values' => ['draft' => 'hidden', 'published' => 'live', 'scheduled' => 'queued'], 'published_at' => 'goes_live_at', 'clear_date_on_unpublish' => false], $config['status']);
        $this->assertStringContainsString("env('POST_MCP_AUTH', 'token')", File::get(config_path('post-mcp.php')));
        $this->assertNotEmpty(File::glob(database_path('migrations/*_create_post_mcp_audit_logs_table.php')));
        $this->assertNotEmpty(File::glob(database_path('migrations/*_create_post_mcp_settings_table.php')));
    }

    public function test_keeps_an_existing_config_without_force(): void
    {
        File::put(config_path('post-mcp.php'), "<?php return ['model' => 'Kept'];");

        $this->artisan('post-mcp:install', ['--model' => UnusualPost::class, '--no-interaction' => true])
            ->expectsOutputToContain('Kept the existing config/post-mcp.php')
            ->assertSuccessful();

        $this->assertSame(['model' => 'Kept'], require config_path('post-mcp.php'));
    }

    public function test_reports_what_is_missing_for_oauth(): void
    {
        config(['auth.guards.api' => null]);

        $this->artisan('post-mcp:install', ['--model' => UnusualPost::class, '--no-interaction' => true])
            ->expectsOutputToContain('Auth guard "api" is configured')
            ->expectsOutputToContain('A "login" route exists for the OAuth consent screen')
            ->expectsOutputToContain('MISSING')
            ->assertSuccessful();
    }

    public function test_fails_for_a_class_that_is_not_a_model(): void
    {
        $this->artisan('post-mcp:install', ['--model' => 'App\\Nope', '--no-interaction' => true])
            ->expectsOutputToContain('No Eloquent model found')
            ->assertFailed();
    }
}
