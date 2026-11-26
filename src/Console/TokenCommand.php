<?php

namespace Smith89k\PostMcp\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Smith89k\PostMcp\PostMcp;

class TokenCommand extends Command
{
    protected $signature = 'post-mcp:token
        {user : The id or email of the user the token acts as}
        {--name=AI app : The app name, shown on the settings page}';

    protected $description = 'Create a fixed access key for an AI app that cannot sign in';

    public function handle(PostMcp $postMcp): int
    {
        $user = $this->findUser((string) $this->argument('user'));

        if ($user === null) {
            $this->components->error('No user found with that id or email.');

            return self::FAILURE;
        }

        if (! method_exists($user, 'createToken')) {
            $this->components->error('The user model has no createToken() method. Add Laravel\Passport\HasApiTokens (or Sanctum\'s HasApiTokens) to it.');

            return self::FAILURE;
        }

        $result = $user->createToken((string) $this->option('name'), ['mcp:use']);
        $token = $result->accessToken ?? $result->plainTextToken ?? null;

        if (! is_string($token)) {
            $this->components->error('The token could not be created.');

            return self::FAILURE;
        }

        if (! $user instanceof Authenticatable || ! Gate::forUser($user)->allows($postMcp->gate())) {
            $this->components->warn("This user is not allowed by the \"{$postMcp->gate()}\" gate yet, so the tools will refuse it.");
        }

        $this->components->info('Token created. It is shown only once:');
        $this->line($token);
        $this->newLine();
        $this->line('Give the app the connector address '.$postMcp->connectorUrl().' and this header:');
        $this->line('  Authorization: Bearer '.$token);
        $this->newLine();
        $this->line('For example, in Claude Code:');
        $this->line('  claude mcp add --transport http posts '.$postMcp->connectorUrl().' --header "Authorization: Bearer '.$token.'"');

        return self::SUCCESS;
    }

    protected function findUser(string $value): ?Model
    {
        $guard = (string) config('post-mcp.auth.guard', 'api');
        $provider = config("auth.guards.{$guard}.provider", 'users');
        $class = config("auth.providers.{$provider}.model");

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        /** @var Model $model */
        $model = new $class;

        return $model->newQuery()
            ->when(str_contains($value, '@'), fn ($query) => $query->where('email', $value), fn ($query) => $query->whereKey($value))
            ->first();
    }
}
