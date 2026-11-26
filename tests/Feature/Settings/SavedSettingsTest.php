<?php

namespace Smith89k\PostMcp\Tests\Feature\Settings;

use Illuminate\Support\Facades\Schema;
use Smith89k\PostMcp\Fields\FieldResolver;
use Smith89k\PostMcp\Settings\SavedSettings;
use Smith89k\PostMcp\Settings\SettingsStore;
use Smith89k\PostMcp\Tests\TestCase;

/**
 * Settings page choices change the field map, inside the limits of the config file.
 */
class SavedSettingsTest extends TestCase
{
    public function test_saved_choices_hide_lock_and_relabel_fields(): void
    {
        app(SettingsStore::class)->replace([
            'excerpt' => ['readable' => false],
            'seo_title' => ['writable' => false, 'label' => 'SEO title', 'description' => 'Shown in search results.'],
            'tags' => ['creatable' => false],
        ]);

        $map = app(FieldResolver::class)->resolve();

        $this->assertFalse($map->get('excerpt')->readable);
        $this->assertFalse($map->get('excerpt')->writable);
        $this->assertTrue($map->get('seo_title')->readable);
        $this->assertFalse($map->get('seo_title')->writable);
        $this->assertSame('SEO title', $map->get('seo_title')->label);
        $this->assertSame('Shown in search results.', $map->get('seo_title')->description);
        $this->assertFalse($map->get('tags')->relation['creatable']);
        $this->assertTrue($map->get('tags')->writable);
    }

    public function test_locked_switches_keep_the_config_settings(): void
    {
        app(SettingsStore::class)->replace([
            'title' => ['readable' => false, 'writable' => false],
            'api_token' => ['readable' => true, 'writable' => true],
            'author' => ['readable' => true, 'creatable' => true],
            'id' => ['writable' => true],
        ]);

        $map = app(FieldResolver::class)->resolve();

        $this->assertTrue($map->get('title')->readable);
        $this->assertTrue($map->get('title')->writable);
        $this->assertFalse($map->get('api_token')->readable);
        $this->assertFalse($map->get('author')->readable);
        $this->assertFalse($map->get('author')->relation['creatable']);
        $this->assertFalse($map->get('id')->writable);
    }

    public function test_details_can_be_switched_off_but_never_added(): void
    {
        config(['post-mcp.relations.tags.details' => ['summary', 'links']]);
        app(SettingsStore::class)->replace(['tags' => ['details_off' => ['links', 'logo']]]);

        $this->assertSame(['summary'], array_keys(app(FieldResolver::class)->resolve()->get('tags')->relation['details']));
    }

    public function test_a_relation_offered_in_config_can_be_made_creatable(): void
    {
        app(SettingsStore::class)->replace(['category' => ['creatable' => true]]);

        $this->assertTrue(app(FieldResolver::class)->resolve()->get('category')->relation['creatable']);
    }

    public function test_locks_explain_which_switches_cannot_move(): void
    {
        $map = app(FieldResolver::class)->baseline();
        $saved = app(SavedSettings::class);

        $this->assertSame(['hide' => null, 'change' => null, 'create' => null], $saved->locks($map->get('excerpt')));
        $this->assertSame('Needed to write posts.', $saved->locks($map->get('title'))['hide']);
        $this->assertSame('Looks like a secret, so it is always hidden.', $saved->locks($map->get('api_token'))['hide']);
        $this->assertNotNull($saved->locks($map->get('author'))['hide']);
        $this->assertNotNull($saved->locks($map->get('author'))['create']);
        $this->assertNotNull($saved->locks($map->get('id'))['change']);
    }

    public function test_a_saved_choice_for_a_removed_field_is_ignored(): void
    {
        app(SettingsStore::class)->replace(['subtitle' => ['readable' => false]]);

        $map = app(FieldResolver::class)->resolve();

        $this->assertFalse($map->has('subtitle'));
        $this->assertTrue($map->get('excerpt')->readable);
    }

    public function test_a_save_takes_effect_without_clearing_the_cache(): void
    {
        $resolver = app(FieldResolver::class);
        $this->assertTrue($resolver->resolve()->get('excerpt')->writable);

        app(SettingsStore::class)->replace(['excerpt' => ['writable' => false]]);

        $this->assertFalse($resolver->resolve()->get('excerpt')->writable);
    }

    public function test_the_field_map_uses_the_config_alone_without_the_settings_table(): void
    {
        Schema::drop('post_mcp_settings');
        $this->app->forgetInstance(SettingsStore::class);

        $map = app(FieldResolver::class)->resolve(fresh: true);

        $this->assertTrue($map->get('excerpt')->writable);
        $this->assertTrue($map->get('tags')->relation['creatable']);
    }
}
