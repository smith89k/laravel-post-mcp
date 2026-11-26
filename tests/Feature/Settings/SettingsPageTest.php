<?php

namespace Smith89k\PostMcp\Tests\Feature\Settings;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Smith89k\PostMcp\Fields\FieldResolver;
use Smith89k\PostMcp\Settings\SettingsStore;
use Smith89k\PostMcp\Tests\TestCase;

/**
 * The page where people allowed by the gate choose what the AI app sees and changes.
 */
class SettingsPageTest extends TestCase
{
    protected function useLoginRedirect($app): void
    {
        $app['config']->set('post-mcp.auth.login_url', '/admin/login');
    }

    protected function turnThePageOff($app): void
    {
        $app['config']->set('post-mcp.ui.enabled', false);
    }

    #[DefineEnvironment('useLoginRedirect')]
    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/laravel-post-mcp/settings')->assertRedirect('/login');
    }

    public function test_people_the_gate_denies_get_forbidden(): void
    {
        $this->actingAs($this->stranger())->get('/laravel-post-mcp/settings')->assertForbidden();
    }

    public function test_shows_the_fields_links_and_connector_address(): void
    {
        $this->actingAs($this->editor())
            ->get('/laravel-post-mcp/settings')
            ->assertOk()
            ->assertSee('What your AI app can do with your posts')
            ->assertSee('Laravel Post MCP')
            ->assertDontSee('Claude')
            ->assertSee('value="http://localhost/laravel-post-mcp"', false)
            ->assertSee('name="fields[excerpt][access]"', false)
            ->assertSee('name="fields[tags][creatable]"', false)
            ->assertSee('Needed to write posts.')
            ->assertSee('Looks like a secret, so it is always hidden.');
    }

    public function test_names_the_ai_app_that_is_connected(): void
    {
        $this->connectApp('ChatGPT');

        $this->actingAs($this->editor())
            ->get('/laravel-post-mcp/settings')
            ->assertOk()
            ->assertSee('What ChatGPT can do with your posts')
            ->assertSee('Note for ChatGPT');
    }

    public function test_lists_every_connected_ai_app_and_skips_disconnected_ones(): void
    {
        $this->connectApp('Claude');
        $this->travel(1)->minutes();
        $this->connectApp('ChatGPT');
        $this->connectApp('Old App', revoked: true);
        $this->connectApp('Expired App', expiresAt: now()->subDay());

        $this->actingAs($this->editor())
            ->get('/laravel-post-mcp/settings')
            ->assertOk()
            ->assertSee('What your AI apps can do with your posts')
            ->assertSeeInOrder(['Connected now', 'ChatGPT', 'Claude'])
            ->assertDontSee('Old App')
            ->assertDontSee('Expired App');
    }

    protected function connectApp(string $name, bool $revoked = false, ?\DateTimeInterface $expiresAt = null): void
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($name, ['https://example.com/callback']);

        Passport::tokenModel()::query()->forceCreate([
            'id' => Str::random(40),
            'user_id' => $this->editor()->id,
            'client_id' => $client->id,
            'scopes' => '["mcp:use"]',
            'revoked' => $revoked,
            'expires_at' => $expiresAt ?? now()->addDay(),
        ]);
    }

    public function test_lists_every_part_of_a_linked_item_with_a_switch_for_each_detail(): void
    {
        config(['post-mcp.relations.tags.details' => ['summary', 'links']]);

        $this->actingAs($this->editor())
            ->get('/laravel-post-mcp/settings')
            ->assertOk()
            ->assertSee('What your AI app can fill in on a new tag')
            ->assertSee('name="fields[tags][details][summary]"', false)
            ->assertSee('name="fields[tags][details][links]"', false)
            ->assertDontSee('name="fields[tags][details][logo]"', false)
            ->assertSee('Logo')
            ->assertSee('Used to find it.')
            ->assertDontSee('Secret Code');
    }

    public function test_switching_a_detail_off_stores_it_and_claude_stops_filling_it_in(): void
    {
        config(['post-mcp.relations.tags.details' => ['summary', 'links']]);

        $this->actingAs($this->editor())
            ->put('/laravel-post-mcp/settings', ['fields' => [
                'tags' => ['access' => 'edit', 'creatable' => '1', 'details' => ['summary' => '0', 'links' => '1', 'logo' => '1']],
            ]])
            ->assertRedirect('/laravel-post-mcp/settings');

        $this->assertSame(['tags' => ['details_off' => ['summary']]], (new SettingsStore)->all());
        $this->assertSame(['links'], array_keys(app(FieldResolver::class)->resolve()->get('tags')->relation['details']));
    }

    public function test_every_list_gets_an_add_more_switch_and_only_lists_do(): void
    {
        config(['post-mcp.relations.tags.details' => ['summary', 'links' => [], 'aliases' => ['grow' => false]]]);

        $this->actingAs($this->editor())
            ->get('/laravel-post-mcp/settings')
            ->assertOk()
            ->assertSee('Can add more to existing ones')
            ->assertSee('name="fields[tags][grow][links]"', false)
            ->assertDontSee('name="fields[tags][grow][summary]"', false)
            ->assertDontSee('name="fields[tags][grow][aliases]"', false)
            ->assertSee('Your developer keeps this list as it is.');
    }

    public function test_switching_add_more_off_stores_it_and_the_list_stops_growing(): void
    {
        config(['post-mcp.relations.tags.details' => ['links' => [], 'aliases' => []]]);

        $this->actingAs($this->editor())
            ->put('/laravel-post-mcp/settings', ['fields' => [
                'tags' => ['access' => 'edit', 'creatable' => '1', 'details' => ['links' => '1', 'aliases' => '1'], 'grow' => ['links' => '0', 'aliases' => '1']],
            ]])
            ->assertRedirect('/laravel-post-mcp/settings');

        $this->assertSame(['tags' => ['grow_off' => ['links']]], (new SettingsStore)->all());

        $details = app(FieldResolver::class)->resolve()->get('tags')->relation['details'];
        $this->assertFalse($details['links']['grow']);
        $this->assertTrue($details['aliases']['grow']);
    }

    public function test_saving_stores_only_the_changes_and_claude_sees_them_at_once(): void
    {
        $this->actingAs($this->editor())
            ->put('/laravel-post-mcp/settings', ['fields' => [
                'excerpt' => ['access' => 'off'],
                'seo_title' => ['access' => 'read', 'label' => 'SEO title', 'description' => 'Shown in search results.'],
                'tags' => ['access' => 'edit', 'creatable' => '0'],
                'category' => ['access' => 'edit', 'creatable' => '1'],
                'title' => ['access' => 'edit', 'label' => 'Title'],
            ]])
            ->assertRedirect('/laravel-post-mcp/settings')
            ->assertSessionHas('post-mcp.status', 'Saved. The change applies right away.');

        $this->assertSame([
            'category' => ['creatable' => true],
            'excerpt' => ['readable' => false],
            'seo_title' => ['writable' => false, 'label' => 'SEO title', 'description' => 'Shown in search results.'],
            'tags' => ['creatable' => false],
        ], (new SettingsStore)->all());

        $map = app(FieldResolver::class)->resolve();
        $this->assertFalse($map->get('excerpt')->readable);
        $this->assertFalse($map->get('seo_title')->writable);
        $this->assertTrue($map->get('category')->relation['creatable']);
    }

    public function test_unknown_fields_and_locked_switches_are_ignored(): void
    {
        $this->actingAs($this->editor())
            ->put('/laravel-post-mcp/settings', ['fields' => [
                'subtitle' => ['access' => 'off'],
                'title' => ['access' => 'off'],
                'api_token' => ['access' => 'edit'],
                'author' => ['access' => 'edit', 'creatable' => '1'],
            ]])
            ->assertRedirect('/laravel-post-mcp/settings');

        $this->assertSame([], (new SettingsStore)->all());
    }

    public function test_a_too_long_label_is_refused(): void
    {
        $this->actingAs($this->editor())
            ->from('/laravel-post-mcp/settings')
            ->put('/laravel-post-mcp/settings', ['fields' => ['excerpt' => ['access' => 'edit', 'label' => str_repeat('x', 101)]]])
            ->assertRedirect('/laravel-post-mcp/settings')
            ->assertSessionHasErrors('fields.excerpt.label');

        $this->assertSame([], (new SettingsStore)->all());
    }

    public function test_reset_clears_every_choice(): void
    {
        app(SettingsStore::class)->replace(['excerpt' => ['readable' => false]]);

        $this->actingAs($this->editor())
            ->delete('/laravel-post-mcp/settings')
            ->assertRedirect('/laravel-post-mcp/settings')
            ->assertSessionHas('post-mcp.status', 'Back to the defaults.');

        $this->assertSame([], (new SettingsStore)->all());
    }

    public function test_asks_for_the_database_update_when_the_table_is_missing(): void
    {
        Schema::drop('post_mcp_settings');

        $this->actingAs($this->editor())
            ->get('/laravel-post-mcp/settings')
            ->assertOk()
            ->assertSee('One setup step left')
            ->assertSee('php artisan migrate');

        $this->actingAs($this->editor())
            ->put('/laravel-post-mcp/settings', ['fields' => ['excerpt' => ['access' => 'off']]])
            ->assertRedirect('/laravel-post-mcp/settings');
    }

    #[DefineEnvironment('turnThePageOff')]
    public function test_the_page_can_be_turned_off(): void
    {
        $this->actingAs($this->editor())->get('/laravel-post-mcp/settings')->assertNotFound();
    }
}
