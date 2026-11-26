<?php

namespace Smith89k\PostMcp\Support;

use Laravel\Passport\Passport;
use Throwable;

/**
 * The AI apps (Claude, ChatGPT, Cursor...) that hold a working key to the site right now,
 * so the pages can call them by name.
 */
class ConnectedApps
{
    /**
     * Names of apps with an access token that is neither revoked nor expired, most recent first.
     * A named token (made with post-mcp:token) is shown by its own name.
     *
     * @return list<string>
     */
    public function names(): array
    {
        if (! class_exists(Passport::class)) {
            return [];
        }

        try {
            $tokenModel = Passport::tokenModel();

            return $tokenModel::query()
                ->with('client')
                ->where('revoked', false)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest()
                ->limit(500)
                ->get()
                ->map(fn ($token): string => trim((string) ($token->getAttribute('name') ?: $token->client?->getAttribute('name'))))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (Throwable) {
            // No Passport tables yet, or a token store this package does not know.
            return [];
        }
    }
}
