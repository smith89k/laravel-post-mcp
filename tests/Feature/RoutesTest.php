<?php

namespace Smith89k\PostMcp\Tests\Feature;

use Laravel\Passport\Passport;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Smith89k\PostMcp\Tests\Fixtures\Models\User;
use Smith89k\PostMcp\Tests\TestCase;

/**
 * The connector URL, its authentication modes and rate limit.
 */
class RoutesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function listTools(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
    }

    protected function useTokenMode($app): void
    {
        $app['config']->set('post-mcp.auth.mode', 'token');
    }

    protected function useLowRateLimit($app): void
    {
        $app['config']->set('post-mcp.route.rate_limit', 2);
    }

    protected function useLoginRedirect($app): void
    {
        $app['config']->set('post-mcp.auth.login_url', '/admin/login');
    }

    public function test_opening_the_connector_address_in_a_browser_leads_to_the_settings_page(): void
    {
        $this->get('/laravel-post-mcp', ['Accept' => 'text/html'])->assertRedirect('/laravel-post-mcp/settings');

        $this->getJson('/laravel-post-mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
    }

    public function test_returns_401_with_a_resource_metadata_hint_without_a_token(): void
    {
        $this->postJson('/laravel-post-mcp', $this->listTools())
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
    }

    public function test_lists_the_tools_with_a_passport_token(): void
    {
        Passport::actingAs($this->editor(), ['mcp:use']);

        $this->postJson('/laravel-post-mcp', $this->listTools())
            ->assertOk()
            ->assertJsonFragment(['name' => 'create_post'])
            ->assertJsonFragment(['name' => 'upload_image'])
            ->assertJsonMissing(['name' => 'delete_post']);
    }

    public function test_tool_schemas_come_from_the_field_map(): void
    {
        Passport::actingAs($this->editor(), ['mcp:use']);

        $tools = collect($this->postJson('/laravel-post-mcp', $this->listTools())->json('result.tools'))->keyBy('name');
        $create = $tools['create_post']['inputSchema'];

        $this->assertSame(['title', 'body'], $create['required']);
        $this->assertSame(70, $create['properties']['seo_title']['maxLength']);
        $this->assertSame('array', $create['properties']['tags']['type']);
        $this->assertArrayNotHasKey('status', $create['properties']);
        $this->assertArrayNotHasKey('internal_notes', $create['properties']);
        $this->assertArrayNotHasKey('api_token', $create['properties']);
        $this->assertTrue($tools['list_posts']['annotations']['readOnlyHint']);
    }

    public function test_oauth_mode_publishes_discovery_metadata_and_client_registration(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/laravel-post-mcp')
            ->assertOk()
            ->assertJsonPath('resource', url('/laravel-post-mcp'));

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('token_endpoint', route('passport.token'));

        $this->assertTrue(app('router')->has('mcp.oauth.register'));
    }

    #[DefineEnvironment('useTokenMode')]
    public function test_token_mode_skips_oauth_discovery_but_accepts_bearer_tokens(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')->assertNotFound();

        Passport::actingAs($this->editor(), ['mcp:use']);

        $this->postJson('/laravel-post-mcp', $this->listTools())->assertOk();
    }

    #[DefineEnvironment('useLowRateLimit')]
    public function test_rate_limits_each_user(): void
    {
        Passport::actingAs($this->editor(), ['mcp:use']);

        $this->postJson('/laravel-post-mcp', $this->listTools())->assertOk();
        $this->postJson('/laravel-post-mcp', $this->listTools())->assertOk();
        $this->postJson('/laravel-post-mcp', $this->listTools())->assertTooManyRequests();
    }

    #[DefineEnvironment('useLoginRedirect')]
    public function test_adds_a_login_redirect_for_the_consent_screen(): void
    {
        $this->get('/login')->assertRedirect('/admin/login');
    }

    public function test_a_user_with_a_token_but_denied_by_the_gate_gets_a_tool_error(): void
    {
        Passport::actingAs(User::query()->create(['name' => 'S', 'email' => 's@example.com', 'password' => 'x']), ['mcp:use']);

        $this->postJson('/laravel-post-mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_posts', 'arguments' => []]])
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }
}
