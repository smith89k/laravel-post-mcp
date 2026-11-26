<?php

namespace Smith89k\PostMcp\Tests\Feature\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Smith89k\PostMcp\Fields\FieldResolver;
use Smith89k\PostMcp\Mcp\PostServer;
use Smith89k\PostMcp\Mcp\Tools\CreatePost;
use Smith89k\PostMcp\Mcp\Tools\GetPostFields;
use Smith89k\PostMcp\Tests\Fixtures\Models\RichPost;
use Smith89k\PostMcp\Tests\Fixtures\Models\Tag;
use Smith89k\PostMcp\Tests\TestCase;

/**
 * Claude fills in the details of related items (a tag's summary, links and logo) while writing a post.
 */
class RelatedDetailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config(['post-mcp.relations.tags' => [
            'label' => 'name',
            'creatable' => true,
            'rules' => ['max:30'],
            'details' => [
                'summary' => ['description' => 'One line about the tag.'],
                'links' => ['rules' => ['max:3'], 'each' => ['url' => ['required', 'url'], 'label' => ['nullable', 'string', 'max:40']]],
                'logo' => ['image' => ['disk' => 'public', 'directory' => 'tags/logos']],
            ],
        ]]);
    }

    public function test_creates_new_tags_with_their_details(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'title' => 'Tagged',
                'body' => 'A body that is long enough.',
                'tags' => ['PHP', ['name' => 'Laravel', 'summary' => 'The PHP framework.', 'links' => [['url' => 'https://laravel.com', 'label' => 'Website']]]],
            ])
            ->assertOk()
            ->assertSee('"tags":["PHP","Laravel"]');

        $laravel = Tag::query()->firstWhere('name', 'Laravel');
        $this->assertSame('The PHP framework.', $laravel->summary);
        $this->assertSame([['url' => 'https://laravel.com', 'label' => 'Website']], $laravel->links);
        $this->assertNull(Tag::query()->firstWhere('name', 'PHP')->summary);
    }

    public function test_fills_only_the_empty_details_of_an_existing_tag(): void
    {
        Tag::query()->create(['name' => 'Laravel', 'summary' => 'Kept as written.']);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'title' => 'Tagged',
                'body' => 'A body that is long enough.',
                'tags' => [['name' => 'Laravel', 'summary' => 'Something else.', 'links' => [['url' => 'https://laravel.com']]]],
            ])
            ->assertOk();

        $laravel = Tag::query()->sole();
        $this->assertSame('Kept as written.', $laravel->summary);
        $this->assertSame([['url' => 'https://laravel.com']], $laravel->links);
        $this->assertTrue(RichPost::query()->sole()->tags->first()->is($laravel));
    }

    public function test_adds_new_links_to_an_existing_tag_without_changing_the_ones_it_has(): void
    {
        Tag::query()->create(['name' => 'Laravel', 'links' => [['url' => 'https://github.com/laravel/laravel', 'label' => 'Source']]]);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'title' => 'Tagged',
                'body' => 'A body that is long enough.',
                'tags' => [['name' => 'Laravel', 'links' => [
                    ['url' => 'https://github.com/laravel/laravel', 'label' => 'Renamed'],
                    ['url' => 'https://laravel.com', 'label' => 'Website'],
                ]]],
            ])
            ->assertOk();

        $this->assertSame([
            ['url' => 'https://github.com/laravel/laravel', 'label' => 'Source'],
            ['url' => 'https://laravel.com', 'label' => 'Website'],
        ], Tag::query()->sole()->links);
    }

    public function test_downloads_a_logo_into_the_configured_folder(): void
    {
        $this->resolveHostsTo(['93.184.216.34']);
        Http::fake(['cdn.example.com/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'title' => 'Tagged',
                'body' => 'A body that is long enough.',
                'tags' => [['name' => 'Laravel', 'logo' => 'https://cdn.example.com/files/laravel.png']],
            ])
            ->assertOk();

        $logo = Tag::query()->sole()->logo;
        $this->assertMatchesRegularExpression('#^tags/logos/laravel-[a-z0-9]{8}\.png$#', $logo);
        Storage::disk('public')->assertExists($logo);
    }

    public function test_checks_every_item_before_creating_anything(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'title' => 'Tagged',
                'body' => 'A body that is long enough.',
                'tags' => ['Fresh', ['name' => 'Broken', 'links' => [['url' => 'not a link']]]],
            ])
            ->assertHasErrors(['tags: Broken: The links url field must be a valid URL.']);

        $this->assertDatabaseCount('tags', 0);
        $this->assertDatabaseCount('rich_posts', 0);
    }

    public function test_refuses_details_it_does_not_know(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'title' => 'Tagged',
                'body' => 'A body that is long enough.',
                'tags' => [['name' => 'Laravel', 'colour' => 'red']],
            ])
            ->assertHasErrors(['tags: Laravel: colour cannot be filled in. Details that can be filled in: summary, links, logo.']);

        $this->assertDatabaseCount('tags', 0);
    }

    public function test_details_are_refused_when_claude_cannot_add_new_ones(): void
    {
        config(['post-mcp.relations.tags.creatable' => false]);
        Tag::query()->create(['name' => 'Laravel']);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'title' => 'Tagged',
                'body' => 'A body that is long enough.',
                'tags' => [['name' => 'Laravel', 'summary' => 'The PHP framework.']],
            ])
            ->assertHasErrors(['tags: Laravel: only existing tags can be linked here, so details cannot be saved. Send just the name.']);

        $this->assertNull(Tag::query()->sole()->summary);
    }

    public function test_get_post_fields_and_the_tool_input_describe_the_details(): void
    {
        PostServer::actingAs($this->editor())
            ->tool(GetPostFields::class)
            ->assertOk()
            ->assertSee(['"details":[{"name":"summary","type":"string","description":"One line about the tag.","adds_to_existing":false}', '{"name":"logo","type":"image URL"']);

        $schema = app(CreatePost::class)->toArray()['inputSchema']['properties']['tags'];

        // Laravel 13 offers "a name or an object"; Laravel 12 has no anyOf, so only the object.
        $object = $schema['items']['anyOf'][1] ?? $schema['items'];

        if (isset($schema['items']['anyOf'])) {
            $this->assertSame('string', $schema['items']['anyOf'][0]['type']);
        }

        $this->assertSame(['name', 'summary', 'links', 'logo'], array_keys($object['properties']));
        $this->assertSame(['name'], $object['required']);
        $this->assertStringContainsString('fill in a new tag', $schema['description']);
    }

    public function test_only_safe_columns_of_the_related_model_can_be_details(): void
    {
        config(['post-mcp.relations.tags.details' => ['summary', 'name', 'id', 'secret_code', 'created_at', 'not_a_column']]);

        $details = app(FieldResolver::class)->build()->get('tags')->relation['details'];

        $this->assertSame(['summary'], array_keys($details));
    }

    public function test_a_list_nobody_described_grows_on_an_existing_tag(): void
    {
        config(['post-mcp.relations.tags.details.aliases' => []]);
        Tag::query()->create(['name' => 'Laravel', 'aliases' => ['laravel-php']]);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'title' => 'Tagged',
                'body' => 'A body that is long enough.',
                'tags' => [['name' => 'Laravel', 'aliases' => ['Laravel-PHP', 'Artisan framework']]],
            ])
            ->assertOk();

        $this->assertSame(['laravel-php', 'Artisan framework'], Tag::query()->sole()->aliases);
    }

    public function test_new_entries_must_look_like_the_saved_ones(): void
    {
        config(['post-mcp.relations.tags.details.aliases' => []]);
        Tag::query()->create(['name' => 'PHP', 'aliases' => [['name' => 'php-lang', 'lang' => 'en']]]);
        Tag::query()->create(['name' => 'Laravel', 'aliases' => [['name' => 'laravel-php', 'lang' => 'en']]]);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'title' => 'Tagged',
                'body' => 'A body that is long enough.',
                'tags' => [['name' => 'Laravel', 'aliases' => [['name' => 'artisan', 'colour' => 'red']]]],
            ])
            ->assertHasErrors(['tags: Laravel: each aliases entry can only have: name, lang.']);

        $this->assertSame([['name' => 'laravel-php', 'lang' => 'en']], Tag::query()->firstWhere('name', 'Laravel')->aliases);
    }

    public function test_a_list_turned_off_for_growing_keeps_its_entries(): void
    {
        config(['post-mcp.relations.tags.details.links.grow' => false]);
        Tag::query()->create(['name' => 'Laravel', 'links' => [['url' => 'https://laravel.com']]]);

        PostServer::actingAs($this->editor())
            ->tool(CreatePost::class, [
                'title' => 'Tagged',
                'body' => 'A body that is long enough.',
                'tags' => [['name' => 'Laravel', 'links' => [['url' => 'https://github.com/laravel']]]],
            ])
            ->assertOk();

        $this->assertSame([['url' => 'https://laravel.com']], Tag::query()->sole()->links);
    }
}
