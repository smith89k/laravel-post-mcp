<?php

namespace Smith89k\PostMcp\Tests\Feature\Fields;

use Smith89k\PostMcp\Fields\Field;
use Smith89k\PostMcp\Fields\FieldResolver;
use Smith89k\PostMcp\Tests\TestCase;

class FieldResolverTest extends TestCase
{
    public function test_simple_post_exposes_title_body_and_status_with_their_types(): void
    {
        $this->useSimplePost();

        $map = app(FieldResolver::class)->build();

        $this->assertSame(Field::TYPE_STRING, $map->get('title')->type);
        $this->assertTrue($map->get('title')->required);
        $this->assertTrue($map->get('title')->writable);
        $this->assertSame(Field::TYPE_TEXT, $map->get('body')->type);
        $this->assertSame('content', $map->get('body')->role);
        $this->assertSame('status', $map->get('status')->role);
        $this->assertFalse($map->get('status')->writable);
    }

    public function test_ids_and_timestamps_are_never_writable(): void
    {
        $map = app(FieldResolver::class)->build();

        foreach (['id', 'created_at', 'updated_at'] as $name) {
            $this->assertTrue($map->get($name)->readable, $name);
            $this->assertFalse($map->get($name)->writable, $name);
        }
    }

    public function test_hidden_and_secret_columns_are_neither_readable_nor_writable(): void
    {
        $map = app(FieldResolver::class)->build();

        $this->assertFalse($map->get('api_token')->readable);
        $this->assertFalse($map->get('api_token')->writable);
    }

    public function test_secret_columns_stay_hidden_even_when_an_override_asks_otherwise(): void
    {
        $this->useUnusualPost();
        config(['post-mcp.fields.overrides' => ['secret_key' => ['read_only' => false, 'hidden' => false]]]);

        $field = app(FieldResolver::class)->build()->get('secret_key');

        $this->assertFalse($field->readable);
        $this->assertFalse($field->writable);
    }

    public function test_guarded_columns_are_read_only(): void
    {
        $map = app(FieldResolver::class)->build();

        $this->assertTrue($map->get('internal_notes')->readable);
        $this->assertFalse($map->get('internal_notes')->writable);
    }

    public function test_form_request_rules_fill_in_required_and_max_length(): void
    {
        $map = app(FieldResolver::class)->build();

        $this->assertSame(120, $map->get('title')->maxLength);
        $this->assertSame(160, $map->get('seo_description')->maxLength);
        $this->assertContains('form_request', $map->get('title')->sources);
    }

    public function test_configured_relations_are_writable_and_others_are_hidden(): void
    {
        $map = app(FieldResolver::class)->build();

        $this->assertTrue($map->get('tags')->writable);
        $this->assertTrue($map->get('tags')->isMultipleRelation());
        $this->assertTrue($map->get('category')->writable);
        $this->assertFalse($map->get('category')->isMultipleRelation());
        $this->assertFalse($map->get('category_id')->writable);
        $this->assertFalse($map->get('author')->readable);
        $this->assertFalse($map->get('author')->writable);
    }

    public function test_author_column_is_managed_by_the_package(): void
    {
        $field = app(FieldResolver::class)->build()->get('user_id');

        $this->assertSame('author', $field->role);
        $this->assertFalse($field->writable);
    }

    public function test_enum_cast_values_become_allowed_values(): void
    {
        $this->useUnusualPost();

        $field = app(FieldResolver::class)->build()->get('state');

        $this->assertSame(['hidden', 'live', 'queued'], $field->enum);
    }

    public function test_unusual_column_names_get_their_roles_from_config(): void
    {
        $this->useUnusualPost();

        $map = app(FieldResolver::class)->build();

        $this->assertSame('headline', $map->byRole('title')->name);
        $this->assertSame('permalink', $map->byRole('slug')->name);
        $this->assertSame('markdown_text', $map->byRole('content')->name);
        $this->assertSame('goes_live_at', $map->byRole('published_at')->name);
        $this->assertStringContainsString('Markdown', $map->get('markdown_text')->description);
        $this->assertFalse($map->get('article_id')->writable);
        $this->assertFalse($map->get('created_on')->writable);
    }

    public function test_explicit_discovery_keeps_only_listed_and_role_fields(): void
    {
        config(['post-mcp.fields.discovery' => 'explicit', 'post-mcp.fields.only' => ['excerpt']]);

        $map = app(FieldResolver::class)->build();

        $this->assertTrue($map->has('excerpt'));
        $this->assertTrue($map->has('title'));
        $this->assertTrue($map->has('tags'));
        $this->assertFalse($map->has('seo_title'));
    }

    public function test_overrides_change_labels_descriptions_and_access(): void
    {
        config(['post-mcp.fields.overrides' => [
            'seo_title' => ['label' => 'SEO title', 'description' => 'For search engines.', 'read_only' => true],
            'excerpt' => ['hidden' => true],
        ], 'post-mcp.fields.except' => ['seo_description']]);

        $map = app(FieldResolver::class)->build();

        $this->assertSame('SEO title', $map->get('seo_title')->label);
        $this->assertSame('For search engines.', $map->get('seo_title')->description);
        $this->assertFalse($map->get('seo_title')->writable);
        $this->assertFalse($map->get('excerpt')->readable);
        $this->assertFalse($map->get('seo_description')->readable);
    }

    public function test_resolve_caches_the_map_until_forgotten(): void
    {
        config(['post-mcp.fields.cache_ttl' => 60]);
        $resolver = app(FieldResolver::class);

        $first = $resolver->resolve();
        $this->assertSame($first, $resolver->resolve());

        $resolver->forget();
        $this->assertNotSame($first, $resolver->resolve());
    }

    public function test_fields_command_prints_the_field_map(): void
    {
        $this->artisan('post-mcp:fields')
            ->expectsOutputToContain('seo_description')
            ->expectsOutputToContain('read/write')
            ->assertSuccessful();
    }
}
