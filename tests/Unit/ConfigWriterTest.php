<?php

namespace Smith89k\PostMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smith89k\PostMcp\Install\ConfigWriter;
use Smith89k\PostMcp\Install\RawPhp;

class ConfigWriterTest extends TestCase
{
    public function test_replaces_top_level_values_and_keeps_comments(): void
    {
        $template = <<<'PHP'
        <?php

        return [

            // The title.
            'title_column' => 'title',

            'status' => [
                'mode' => 'column',
                'values' => ['draft' => 'draft'],
            ],

            'delete' => [
                'enabled' => false,
            ],

        ];
        PHP;

        $output = (new ConfigWriter)->render($template, [
            'title_column' => 'headline',
            'status' => ['mode' => 'boolean', 'values' => ['draft' => false, 'published' => true], 'reserved' => new RawPhp("config('blog.reserved')")],
        ]);

        $this->assertStringContainsString("// The title.\n    'title_column' => 'headline',", $output);
        $this->assertStringContainsString("        'mode' => 'boolean',\n        'values' => [\n            'draft' => false,\n            'published' => true,\n        ],", $output);
        $this->assertStringContainsString("'reserved' => config('blog.reserved'),", $output);
        $this->assertStringContainsString("'delete' => [\n        'enabled' => false,\n    ],", $output);
    }
}
