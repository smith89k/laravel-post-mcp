<?php

namespace Smith89k\PostMcp\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One change made through the MCP tools.
 *
 * @property int $id
 * @property string $tool
 * @property string|null $post_id
 * @property string $post_type
 * @property array<int, string>|null $changed_fields
 * @property array<string, mixed>|null $meta
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('post-mcp.audit.table', 'post_mcp_audit_logs');
    }

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'meta' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }
}
