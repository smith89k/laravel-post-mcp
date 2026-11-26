<?php

namespace Smith89k\PostMcp;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Passport;
use Smith89k\PostMcp\Console\FieldsCommand;
use Smith89k\PostMcp\Console\InstallCommand;
use Smith89k\PostMcp\Console\TokenCommand;
use Smith89k\PostMcp\Contracts\PostWriter;
use Smith89k\PostMcp\Fields\FieldResolver;
use Smith89k\PostMcp\Http\SettingsController;
use Smith89k\PostMcp\Mcp\PostServer;
use Smith89k\PostMcp\Settings\SettingsStore;
use Smith89k\PostMcp\Writing\EloquentPostWriter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PostMcpServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-post-mcp')
            ->hasConfigFile('post-mcp')
            ->hasViews('post-mcp')
            ->hasMigrations(['create_post_mcp_audit_logs_table', 'create_post_mcp_settings_table'])
            ->hasCommands([
                InstallCommand::class,
                FieldsCommand::class,
                TokenCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PostMcp::class);
        $this->app->singleton(FieldResolver::class);
        $this->app->singleton(SettingsStore::class);

        $this->app->bindIf(PostWriter::class, function ($app): PostWriter {
            $class = config('post-mcp.writer');

            return is_string($class) && $class !== '' ? $app->make($class) : $app->make(EloquentPostWriter::class);
        });
    }

    public function packageBooted(): void
    {
        $this->registerRateLimiter();
        $this->registerAuthorizationView();
        $this->allowAppSchemes();

        if (! $this->app->routesAreCached()) {
            $this->registerRoutes();
            $this->registerSettingsPageRoutes();
        }
    }

    /**
     * Lets desktop AI apps (Cursor, VS Code...) register return addresses in their own scheme,
     * added to whatever laravel/mcp already allows.
     */
    protected function allowAppSchemes(): void
    {
        $schemes = array_values(array_unique(array_filter([
            ...(array) config('mcp.custom_schemes', []),
            ...(array) config('post-mcp.auth.app_schemes', []),
        ], 'is_string')));

        config(['mcp.custom_schemes' => $schemes]);
    }

    protected function registerSettingsPageRoutes(): void
    {
        $postMcp = $this->app->make(PostMcp::class);

        if (! $postMcp->settingsPageEnabled()) {
            return;
        }

        $path = $postMcp->settingsPagePath();

        Route::middleware([...(array) config('post-mcp.ui.middleware', ['web', 'auth']), 'can:'.$postMcp->gate()])
            ->group(function () use ($path): void {
                Route::get($path, [SettingsController::class, 'show'])->name('post-mcp.settings');
                Route::put($path, [SettingsController::class, 'update'])->name('post-mcp.settings.update');
                Route::delete($path, [SettingsController::class, 'reset'])->name('post-mcp.settings.reset');
            });
    }

    protected function registerRateLimiter(): void
    {
        RateLimiter::for('post-mcp', function (Request $request): Limit {
            $user = $request->user();
            $key = $user instanceof Authenticatable ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip();

            return Limit::perMinute(max(1, (int) config('post-mcp.route.rate_limit', 60)))->by($key);
        });
    }

    protected function registerRoutes(): void
    {
        if (! config('post-mcp.route.enabled', true)) {
            return;
        }

        $postMcp = $this->app->make(PostMcp::class);
        $middleware = ['auth:'.$postMcp->guard()];

        if (filled(config('post-mcp.route.rate_limit'))) {
            $middleware[] = 'throttle:post-mcp';
        }

        $middleware = [...$middleware, ...(array) config('post-mcp.route.middleware', [])];

        if ($postMcp->authMode() === 'oauth' && class_exists(Passport::class)) {
            $limit = (int) config('post-mcp.auth.oauth_rate_limit', 10);

            Route::middleware($limit > 0 ? ["throttle:{$limit},1"] : [])->group(function (): void {
                Mcp::oauthRoutes(registration: (bool) config('post-mcp.auth.oauth_registration', true));
            });
        }

        $connectorPath = Str::start(trim((string) config('post-mcp.route.path', 'laravel-post-mcp'), '/'), '/');

        Mcp::web($connectorPath, PostServer::class)
            ->middleware($middleware)
            ->name('post-mcp.server');

        // A person who opens the connector address in a browser lands on the settings page.
        // AI apps still get MCP's "use POST" answer.
        Route::get($connectorPath, function (Request $request, PostMcp $postMcp) {
            return $request->acceptsHtml() && ! $request->expectsJson() && $postMcp->settingsPageEnabled()
                ? redirect()->to($postMcp->settingsPageUrl())
                : response('', 405)->header('Allow', 'POST');
        });

        $loginUrl = config('post-mcp.auth.login_url');

        if (is_string($loginUrl) && $loginUrl !== '' && ! Route::has('login')) {
            Route::get('login', fn () => redirect()->to($loginUrl))
                ->middleware('web')
                ->name('login');
        }
    }

    protected function registerAuthorizationView(): void
    {
        if (config('post-mcp.auth.mode') !== 'oauth'
            || ! config('post-mcp.auth.register_authorization_view', true)
            || ! class_exists(Passport::class)) {
            return;
        }

        Passport::authorizationView(fn (array $parameters) => View::exists('mcp.authorize')
            ? response()->view('mcp.authorize', $parameters)
            : response()->view('post-mcp::authorize', [...$parameters, ...$this->authorizationDetails()]));
    }

    /**
     * What the consent screen shows besides Passport's data: the site, the settings
     * page and how much Claude can do with posts right now.
     *
     * @return array{siteName: string, settingsUrl: string|null, canEdit: int|null, canRead: int|null}
     */
    protected function authorizationDetails(): array
    {
        $postMcp = $this->app->make(PostMcp::class);
        $canEdit = null;
        $canRead = null;

        try {
            $readable = $this->app->make(FieldResolver::class)->resolve()->readable();
            $canEdit = count(array_filter($readable, fn ($field): bool => $field->writable));
            $canRead = count($readable) - $canEdit;
        } catch (\Throwable) {
            // The screen still works without the numbers, for example before the post table exists.
        }

        return [
            'siteName' => (string) config('app.name', 'Laravel'),
            'settingsUrl' => $postMcp->settingsPageEnabled() ? $postMcp->settingsPageUrl() : null,
            'canEdit' => $canEdit,
            'canRead' => $canRead,
        ];
    }
}
