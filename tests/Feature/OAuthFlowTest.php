<?php

namespace Smith89k\PostMcp\Tests\Feature;

use Illuminate\Support\Str;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Smith89k\PostMcp\Tests\TestCase;

/**
 * The whole flow a claude.ai connector goes through: register, sign in, approve,
 * swap the code for a token (PKCE) and call a tool.
 */
class OAuthFlowTest extends TestCase
{
    protected const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    protected function useLoginRedirect($app): void
    {
        $app['config']->set('post-mcp.auth.login_url', '/admin/login');
    }

    public function test_desktop_apps_with_their_own_return_address_can_register(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Cursor',
            'redirect_uris' => ['cursor://anysphere.cursor-retrieval/oauth/user-posts/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ])->assertSuccessful();

        $this->postJson('/oauth/register', [
            'client_name' => 'Unknown',
            'redirect_uris' => ['made-up-scheme://somewhere/callback'],
        ])->assertClientError();
    }

    #[DefineEnvironment('useLoginRedirect')]
    public function test_claude_can_register_sign_in_and_call_tools(): void
    {
        $client = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ])->assertSuccessful()->json();

        $verifier = Str::random(64);
        $query = http_build_query([
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'state-123',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        $this->get('/oauth/authorize?'.$query)->assertRedirect('/login');
        $this->get('/login')->assertRedirect('/admin/login');

        $editor = $this->editor();
        $this->withoutVite();

        $this->actingAs($editor, 'web')
            ->get('/oauth/authorize?'.$query)
            ->assertOk()
            ->assertSee('Let Claude use your posts?')
            ->assertSee(self::ALLOWED_EMAIL)
            ->assertSee('claude.ai')
            ->assertSee('action="'.route('passport.authorizations.approve').'"', false)
            ->assertSee('name="auth_token"', false)
            ->assertSee('href="http://localhost/laravel-post-mcp/settings"', false);

        $approved = $this->actingAs($editor, 'web')->post('/oauth/authorize', [
            'state' => 'state-123',
            'client_id' => $client['client_id'],
            'auth_token' => session('authToken'),
        ]);

        $location = (string) $approved->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT, $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame('state-123', $params['state']);

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
            'code' => $params['code'],
        ])->assertOk()->json('access_token');

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson('/laravel-post-mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_posts', 'arguments' => []]])
            ->assertOk()
            ->assertJsonPath('result.isError', false);
    }
}
