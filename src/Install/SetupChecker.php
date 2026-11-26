<?php

namespace Smith89k\PostMcp\Install;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Smith89k\PostMcp\PostMcp;
use Throwable;

/**
 * Checks what a site still needs before Claude can connect.
 */
class SetupChecker
{
    public function __construct(protected PostMcp $postMcp) {}

    /**
     * @return list<array{label: string, ok: bool, fix: string|null}>
     */
    public function checks(): array
    {
        $checks = [
            $this->check(
                'Post model '.$this->modelName(),
                $this->modelOk(),
                'Set post-mcp.model to your Eloquent post model.',
            ),
            $this->check(
                "Gate \"{$this->postMcp->gate()}\" is defined",
                Gate::has($this->postMcp->gate()),
                "Add to AppServiceProvider::boot(): Gate::define('{$this->postMcp->gate()}', fn (\$user) => \$user->isAdmin()); Until then everyone is denied.",
            ),
            $this->check(
                'Audit log table exists',
                ! $this->postMcp->config('audit.enabled', true) || $this->hasTable((string) $this->postMcp->config('audit.table')),
                'Run: php artisan migrate',
            ),
            $this->check(
                'APP_URL uses https',
                str_starts_with((string) config('app.url'), 'https://') || app()->environment('local', 'testing'),
                'AI apps only connect to https addresses. Set APP_URL=https://your-domain.',
            ),
        ];

        $guard = $this->postMcp->guard();
        $driver = config("auth.guards.{$guard}.driver");

        $checks[] = $this->check(
            "Auth guard \"{$guard}\" is configured",
            is_string($driver),
            "Add an \"{$guard}\" guard to config/auth.php, e.g. ['driver' => 'passport', 'provider' => 'users'].",
        );

        if ($this->postMcp->authMode() === 'oauth' || $driver === 'passport') {
            $checks = [...$checks, ...$this->passportChecks($guard, $driver)];
        }

        if ($this->postMcp->authMode() === 'oauth') {
            $checks[] = $this->check(
                'A "login" route exists for the OAuth consent screen',
                Route::has('login') || filled($this->postMcp->config('auth.login_url')),
                'Passport sends signed-out users to route("login"). Set POST_MCP_LOGIN_URL (e.g. /admin/login for Filament) or add a login route.',
            );
        }

        return $checks;
    }

    /**
     * @return list<array{label: string, ok: bool, fix: string|null}>
     */
    protected function passportChecks(string $guard, mixed $driver): array
    {
        $installed = class_exists(Passport::class);
        $checks = [$this->check('laravel/passport is installed', $installed, 'Run: composer require laravel/passport && php artisan install:api --passport')];

        if (! $installed) {
            return $checks;
        }

        $provider = config("auth.guards.{$guard}.provider", 'users');
        $userModel = config("auth.providers.{$provider}.model");
        $traits = is_string($userModel) && class_exists($userModel) ? class_uses_recursive($userModel) : [];

        return [
            ...$checks,
            $this->check("Guard \"{$guard}\" uses the passport driver", $driver === 'passport', "Set 'driver' => 'passport' for the \"{$guard}\" guard in config/auth.php."),
            $this->check(
                'User model uses Passport\'s HasApiTokens and OAuthenticatable',
                in_array('Laravel\\Passport\\HasApiTokens', $traits, true) && is_string($userModel) && is_subclass_of($userModel, 'Laravel\\Passport\\Contracts\\OAuthenticatable'),
                'Add "use Laravel\\Passport\\HasApiTokens;" and "implements Laravel\\Passport\\Contracts\\OAuthenticatable" to your User model.',
            ),
            $this->check('Passport tables exist', $this->hasTable('oauth_clients'), 'Run: php artisan vendor:publish --tag=passport-migrations && php artisan migrate'),
            $this->check(
                'Passport keys exist',
                filled(config('passport.private_key')) || file_exists(Passport::keyPath('oauth-private.key')),
                'Run: php artisan passport:keys (or set PASSPORT_PRIVATE_KEY and PASSPORT_PUBLIC_KEY in production).',
            ),
            ...($this->postMcp->authMode() === 'token' ? [$this->check(
                'A personal access client exists (token mode)',
                $this->hasPersonalAccessClient(),
                'Run: php artisan passport:client --personal',
            )] : []),
        ];
    }

    /**
     * @return array{label: string, ok: bool, fix: string|null}
     */
    protected function check(string $label, bool $ok, string $fix): array
    {
        return ['label' => $label, 'ok' => $ok, 'fix' => $ok ? null : $fix];
    }

    protected function modelOk(): bool
    {
        try {
            $this->postMcp->modelClass();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    protected function modelName(): string
    {
        $model = $this->postMcp->config('model');

        return is_string($model) ? $model : '(not set)';
    }

    protected function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    protected function hasPersonalAccessClient(): bool
    {
        try {
            return DB::table('oauth_clients')->where('grant_types', 'like', '%personal_access%')->where('revoked', false)->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
