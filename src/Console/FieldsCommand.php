<?php

namespace Smith89k\PostMcp\Console;

use Illuminate\Console\Command;
use Smith89k\PostMcp\Fields\FieldResolver;

class FieldsCommand extends Command
{
    protected $signature = 'post-mcp:fields
        {--json : Print the field map as JSON}';

    protected $description = 'Print the field map the AI app sees for the post model (rebuilds the cache)';

    public function handle(FieldResolver $resolver): int
    {
        $resolver->forget();
        $map = $resolver->resolve(fresh: true);

        if ($this->option('json')) {
            $this->line((string) json_encode($map->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        FieldTable::render($this, $map);

        return self::SUCCESS;
    }
}
