<?php

namespace Smith89k\PostMcp\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Smith89k\PostMcp\Fields\FieldResolver;
use Smith89k\PostMcp\Install\ConfigGuesser;
use Smith89k\PostMcp\Install\ConfigWriter;
use Smith89k\PostMcp\Install\SetupChecker;
use Smith89k\PostMcp\PostMcp;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\suggest;

class InstallCommand extends Command
{
    protected $signature = 'post-mcp:install
        {--model= : The post model class, e.g. App\\Models\\Post}
        {--auth= : oauth (apps sign in on your site) or token (a fixed access key)}
        {--force : Overwrite an existing config/post-mcp.php}
        {--migrate : Run the migrations without asking}';

    protected $description = 'Connect AI apps to your post model: detect the fields, write config/post-mcp.php and check the OAuth setup';

    public function handle(ConfigGuesser $guesser, ConfigWriter $writer, Filesystem $files, PostMcp $postMcp): int
    {
        $this->components->info('Laravel Post MCP: let AI apps manage your posts.');

        $model = $this->askForModel($guesser);

        if ($model === null) {
            $this->components->error('No Eloquent model found. Pass one with --model="App\\Models\\Post".');

            return self::FAILURE;
        }

        $settings = $guesser->guess($model);
        $settings = $this->confirmSettings($settings);
        $settings['auth'] = [...(array) config('post-mcp.auth', []), 'mode' => $this->askForAuthMode()];

        foreach ($settings as $key => $value) {
            config(["post-mcp.{$key}" => $value]);
        }

        $this->components->twoColumnDetail('Detected field map', "<fg=gray>{$model}</>");
        FieldTable::render($this, app(FieldResolver::class)->build());

        if (! $this->writeConfig($writer, $files, $settings)) {
            return self::FAILURE;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'post-mcp-migrations']);
        $this->components->task('Published the audit log and settings page migrations');

        if ($this->option('migrate') || ($this->input->isInteractive() && confirm('Run the database migrations now?', true))) {
            $this->call('migrate', ['--force' => true]);
        }

        app(FieldResolver::class)->forget();

        $this->printChecks(app(SetupChecker::class));
        $this->printNextSteps($postMcp);

        return self::SUCCESS;
    }

    /**
     * @return class-string<Model>|null
     */
    protected function askForModel(ConfigGuesser $guesser): ?string
    {
        $model = $this->option('model');

        if (! is_string($model) || $model === '') {
            $candidates = $guesser->candidateModels(app_path('Models'));
            $default = $candidates[0] ?? 'App\\Models\\Post';

            $model = $this->input->isInteractive()
                ? suggest('Which model holds your posts?', $candidates, default: $default, required: true)
                : $default;
        }

        $model = ltrim((string) $model, '\\');

        return class_exists($model) && is_subclass_of($model, Model::class) ? $model : null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function confirmSettings(array $settings): array
    {
        if (! $this->input->isInteractive()) {
            return $settings;
        }

        $settings['content']['format'] = select(
            'How is the post body stored?',
            ['html' => 'HTML', 'markdown' => 'Markdown', 'blocks' => 'Blocks (JSON)'],
            default: $settings['content']['format'],
        );

        if ($settings['relations'] !== []) {
            $writable = multiselect(
                'Which relations may the AI app set?',
                array_combine(array_keys($settings['relations']), array_keys($settings['relations'])),
                default: array_keys($settings['relations']),
            );
            $settings['relations'] = array_intersect_key($settings['relations'], array_flip($writable));

            if ($settings['relations'] !== []) {
                $creatable = multiselect(
                    'Which of them may the AI app create new values for (e.g. new tags)?',
                    array_combine(array_keys($settings['relations']), array_keys($settings['relations'])),
                    default: array_keys(array_filter($settings['relations'], fn (array $relation): bool => $relation['creatable'])),
                );

                foreach ($settings['relations'] as $name => $relation) {
                    $settings['relations'][$name]['creatable'] = in_array($name, $creatable, true);
                }
            }
        }

        $settings['media']['driver'] = select(
            'Where should uploaded images go?',
            ['disk' => 'A filesystem disk (public, s3...)', 'medialibrary' => 'spatie/laravel-medialibrary', 'none' => 'Turn image uploads off'],
            default: $settings['media']['driver'],
        );

        return $settings;
    }

    protected function askForAuthMode(): string
    {
        $mode = $this->option('auth');

        if (in_array($mode, ['oauth', 'token'], true)) {
            return $mode;
        }

        if (! $this->input->isInteractive()) {
            return 'oauth';
        }

        return select('How will AI apps sign in?', [
            'oauth' => 'OAuth (Claude, ChatGPT, Cursor, VS Code and most apps) - needs Laravel Passport',
            'token' => 'A fixed access key (for apps that cannot sign in)',
        ], default: 'oauth');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function writeConfig(ConfigWriter $writer, Filesystem $files, array $settings): bool
    {
        $path = config_path('post-mcp.php');

        if ($files->exists($path) && ! $this->option('force')) {
            $overwrite = $this->input->isInteractive() && confirm('config/post-mcp.php already exists. Overwrite it?', false);

            if (! $overwrite) {
                $this->components->warn('Kept the existing config/post-mcp.php. Use --force to overwrite it.');

                return true;
            }
        }

        $mode = (string) ($settings['auth']['mode'] ?? 'oauth');
        unset($settings['auth']);

        $template = $files->get(__DIR__.'/../../config/post-mcp.php');
        $template = str_replace("env('POST_MCP_AUTH', 'oauth')", "env('POST_MCP_AUTH', '{$mode}')", $template);
        $files->put($path, $writer->render($template, $settings));

        $this->components->task('Wrote config/post-mcp.php');

        return true;
    }

    protected function printChecks(SetupChecker $checker): void
    {
        $this->newLine();
        $this->components->info('Setup checks');

        foreach ($checker->checks() as $check) {
            $this->components->twoColumnDetail($check['label'], $check['ok'] ? '<fg=green>OK</>' : '<fg=red>MISSING</>');

            if ($check['fix'] !== null) {
                $this->line("  <fg=gray>→ {$check['fix']}</>");
            }
        }
    }

    protected function printNextSteps(PostMcp $postMcp): void
    {
        $url = $postMcp->connectorUrl();

        $this->newLine();
        $this->components->info("Connector URL: {$url}");

        if ($postMcp->settingsPageEnabled()) {
            $this->components->info("Settings page: {$postMcp->settingsPageUrl()}");
            $this->line('  Choose by clicking which fields the AI app sees and changes.');
            $this->newLine();
        }

        $this->line('  <options=bold>Claude, ChatGPT and other chat apps</>');
        $this->line('    Add a custom connector (MCP server) in the app\'s settings and paste the URL above.');
        $this->line('    The app opens your site to sign in and approve access.');
        $this->newLine();
        $this->line('  <options=bold>Cursor, VS Code and other editors</>');
        $this->line('    Add an MCP server of type "http" with the URL above to the editor\'s mcp.json.');
        $this->newLine();
        $this->line('  <options=bold>Claude Code</>');
        $this->line("    claude mcp add --transport http posts {$url}");

        if ($postMcp->authMode() === 'token') {
            $this->line('    Token mode: php artisan post-mcp:token you@example.com, then add --header "Authorization: Bearer <token>".');
        }

        $this->newLine();
        $this->line('  Review the field map any time with: php artisan post-mcp:fields');
    }
}
