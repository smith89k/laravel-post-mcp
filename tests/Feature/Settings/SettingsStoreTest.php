<?php

namespace Smith89k\PostMcp\Tests\Feature\Settings;

use Illuminate\Support\Facades\Schema;
use Smith89k\PostMcp\Settings\SettingsStore;
use Smith89k\PostMcp\Tests\TestCase;

/**
 * Choices made on the settings page, kept in the database.
 */
class SettingsStoreTest extends TestCase
{
    public function test_all_is_empty_and_not_ready_without_the_table(): void
    {
        Schema::drop('post_mcp_settings');

        $store = new SettingsStore;

        $this->assertFalse($store->ready());
        $this->assertSame([], $store->all());
    }

    public function test_replace_stores_only_non_empty_settings_and_reset_clears_them(): void
    {
        $store = app(SettingsStore::class);

        $store->replace([
            'excerpt' => ['writable' => false, 'label' => 'Summary'],
            'seo_title' => [],
        ]);

        $this->assertTrue($store->ready());
        $this->assertSame(['excerpt' => ['writable' => false, 'label' => 'Summary']], $store->all());
        $this->assertSame(['excerpt' => ['writable' => false, 'label' => 'Summary']], (new SettingsStore)->all());

        $store->reset();

        $this->assertSame([], $store->all());
        $this->assertDatabaseCount('post_mcp_settings', 0);
    }
}
