<?php

namespace Smith89k\PostMcp\Audit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Smith89k\PostMcp\PostMcp;

/**
 * Records who changed which post through which tool.
 */
class AuditLogger
{
    public function __construct(protected PostMcp $postMcp) {}

    /**
     * @param  list<string>  $changedFields
     * @param  array<string, mixed>  $meta
     */
    public function record(string $tool, ?Authenticatable $user, ?Model $post, array $changedFields = [], array $meta = []): void
    {
        if (! $this->postMcp->config('audit.enabled', true)) {
            return;
        }

        $entry = [
            'tool' => $tool,
            'user_type' => $user instanceof Model ? $user->getMorphClass() : null,
            'user_id' => $user?->getAuthIdentifier(),
            'post_type' => $this->postMcp->modelClass(),
            'post_id' => $post?->getKey() !== null ? (string) $post->getKey() : null,
            'changed_fields' => array_values($changedFields),
            'meta' => $meta === [] ? null : $meta,
        ];

        AuditLog::query()->create($entry);

        $channel = $this->postMcp->config('audit.log_channel');

        if (is_string($channel) && $channel !== '') {
            Log::channel($channel)->info("post-mcp: {$tool}", $entry);
        }
    }
}
