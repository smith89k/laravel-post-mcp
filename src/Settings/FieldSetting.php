<?php

namespace Smith89k\PostMcp\Settings;

use Illuminate\Database\Eloquent\Model;

/**
 * The settings page's choices for one field.
 *
 * @property int $id
 * @property string $field
 * @property array{readable?: bool, writable?: bool, creatable?: bool, label?: string, description?: string} $settings
 */
class FieldSetting extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('post-mcp.ui.table', 'post_mcp_settings');
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
