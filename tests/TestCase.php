<?php

namespace Smith89k\PostMcp\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Passport\PassportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Smith89k\PostMcp\Media\HostResolver;
use Smith89k\PostMcp\PostMcpServiceProvider;
use Smith89k\PostMcp\Tests\Fixtures\Models\RichPost;
use Smith89k\PostMcp\Tests\Fixtures\Models\SimplePost;
use Smith89k\PostMcp\Tests\Fixtures\Models\UnusualPost;
use Smith89k\PostMcp\Tests\Fixtures\Models\User;
use Smith89k\PostMcp\Tests\Fixtures\Requests\StoreRichPostRequest;
use Smith89k\PostMcp\Tests\Fixtures\Requests\UpdateRichPostRequest;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Users allowed by the gate in tests.
     */
    public const ALLOWED_EMAIL = 'editor@example.com';

    /**
     * A 1x1 PNG.
     */
    protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * Makes every host look like it lives at these addresses, so image downloads need no network.
     *
     * @param  list<string>  $ips
     */
    protected function resolveHostsTo(array $ips): void
    {
        $this->app->instance(HostResolver::class, new class($ips) extends HostResolver
        {
            /**
             * @param  list<string>  $ips
             */
            public function __construct(protected array $ips) {}

            public function resolve(string $host): array
            {
                return $this->ips;
            }
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        RichPost::$savingEvents = 0;

        Gate::define('use-post-mcp', fn (User $user): bool => $user->email === self::ALLOWED_EMAIL);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/blog/{post}', fn () => 'post')->name('rich.show');
    }

    protected function getPackageProviders($app): array
    {
        return [
            McpServiceProvider::class,
            PassportServiceProvider::class,
            PostMcpServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://blog.test');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.guards.api', ['driver' => 'passport', 'provider' => 'users']);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('passport.private_key', self::passportKeys()['private']);
        $app['config']->set('passport.public_key', self::passportKeys()['public']);

        $this->useRichPost($app['config']);
    }

    /**
     * One RSA key pair for the whole run, so Passport can sign and check tokens.
     *
     * Made with phpseclib, the library Passport's own passport:keys command uses,
     * because openssl_pkey_new() fails on Windows when PHP cannot find openssl.cnf.
     *
     * @return array{private: string, public: string}
     */
    protected static function passportKeys(): array
    {
        static $keys = null;

        if ($keys === null) {
            /** @var class-string $rsa */
            $rsa = class_exists('phpseclib4\Crypt\RSA') ? 'phpseclib4\Crypt\RSA' : 'phpseclib3\Crypt\RSA';
            $key = $rsa::createKey(2048);
            $keys = ['private' => (string) $key, 'public' => (string) $key->getPublicKey()];
        }

        return $keys;
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(dirname((string) (new \ReflectionClass(PassportServiceProvider::class))->getFileName(), 2).'/database/migrations');
    }

    protected function editor(): User
    {
        return User::query()->firstOrCreate(['email' => self::ALLOWED_EMAIL], ['name' => 'Editor', 'password' => 'secret']);
    }

    protected function stranger(): User
    {
        return User::query()->firstOrCreate(['email' => 'stranger@example.com'], ['name' => 'Stranger', 'password' => 'secret']);
    }

    /**
     * @param  Repository|null  $config
     */
    protected function useSimplePost($config = null): void
    {
        $this->setPostConfig($config, [
            'model' => SimplePost::class,
            'title_column' => 'title',
            'author_column' => null,
            'status' => [
                'mode' => 'column',
                'column' => 'status',
                'values' => ['draft' => 'draft', 'published' => 'published', 'scheduled' => null],
                'published_at' => null,
            ],
            'slug' => ['column' => null, 'strategy' => 'none'],
            'content' => ['column' => 'body', 'format' => 'html'],
            'validation' => ['create' => null, 'update' => null],
            'relations' => [],
            'media' => ['driver' => 'none'],
            'public_url' => ['route' => null],
            'list' => ['search' => ['title', 'body']],
        ]);
    }

    /**
     * @param  Repository|null  $config
     */
    protected function useRichPost($config = null): void
    {
        $this->setPostConfig($config, [
            'model' => RichPost::class,
            'title_column' => 'title',
            'author_column' => 'user_id',
            'status' => [
                'mode' => 'column',
                'column' => 'status',
                'values' => ['draft' => 'draft', 'published' => 'published', 'scheduled' => 'scheduled'],
                'published_at' => 'published_at',
            ],
            'slug' => ['column' => 'slug', 'strategy' => 'from_title', 'source' => 'title', 'reserved' => ['admin', 'feed']],
            'content' => ['column' => 'body', 'format' => 'html'],
            'validation' => ['create' => StoreRichPostRequest::class, 'update' => UpdateRichPostRequest::class],
            'relations' => [
                'category' => ['label' => 'name'],
                'tags' => ['label' => 'name', 'creatable' => true, 'rules' => ['max:30']],
            ],
            'media' => [
                'driver' => 'disk',
                'disk' => 'public',
                'directory' => 'blog',
                'attach_to' => 'featured_image',
                'attach_value' => 'path',
                'max_kb' => 100,
                'mimes' => ['jpg', 'png', 'gif', 'webp'],
            ],
            'public_url' => ['route' => 'rich.show'],
            'list' => ['search' => ['title', 'excerpt', 'body']],
        ]);
    }

    /**
     * @param  Repository|null  $config
     */
    protected function useUnusualPost($config = null): void
    {
        $this->setPostConfig($config, [
            'model' => UnusualPost::class,
            'title_column' => 'headline',
            'author_column' => null,
            'status' => [
                'mode' => 'column',
                'column' => 'state',
                'values' => ['draft' => 'hidden', 'published' => 'live', 'scheduled' => 'queued'],
                'published_at' => 'goes_live_at',
            ],
            'slug' => ['column' => 'permalink', 'strategy' => 'from_title', 'source' => 'headline', 'reserved' => []],
            'content' => ['column' => 'markdown_text', 'format' => 'markdown'],
            'validation' => ['create' => null, 'update' => null],
            'relations' => [],
            'media' => ['driver' => 'none'],
            'public_url' => ['route' => null],
            'list' => ['search' => ['headline']],
        ]);
    }

    /**
     * @param  Repository|null  $config
     * @param  array<string, mixed>  $values
     */
    protected function setPostConfig($config, array $values): void
    {
        $config ??= $this->app['config'];

        foreach ($values as $key => $value) {
            $config->set("post-mcp.{$key}", is_array($value) && ! array_is_list($value) && in_array($key, ['status', 'slug', 'content', 'media', 'list', 'public_url'], true)
                ? array_replace((array) $config->get("post-mcp.{$key}", []), $value)
                : $value);
        }

        $config->set('post-mcp.fields.cache_ttl', 0);
    }
}
