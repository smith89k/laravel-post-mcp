<?php

namespace Smith89k\PostMcp\Install;

/**
 * Writes config/post-mcp.php from the package's commented config file, replacing
 * the values of the given top-level keys and keeping every comment.
 */
class ConfigWriter
{
    /**
     * @param  array<string, mixed>  $values  top-level keys to replace
     */
    public function render(string $template, array $values): string
    {
        $lines = explode("\n", $template);

        foreach ($values as $key => $value) {
            $start = $this->findKey($lines, $key);

            if ($start === null) {
                continue;
            }

            $end = $this->findEnd($lines, $start);
            $replacement = explode("\n", "    '{$key}' => ".$this->export($value, 1, $key).',');

            array_splice($lines, $start, $end - $start + 1, $replacement);
        }

        return implode("\n", $lines);
    }

    public function export(mixed $value, int $depth = 0, ?string $key = null): string
    {
        if ($value instanceof RawPhp) {
            return $value->code;
        }

        if ($key === 'model' && is_string($value) && class_exists($value)) {
            return '\\'.ltrim($value, '\\').'::class';
        }

        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $indent = str_repeat('    ', $depth + 1);
            $isList = array_is_list($value);
            $items = [];

            foreach ($value as $itemKey => $item) {
                $prefix = $isList ? '' : var_export($itemKey, true).' => ';
                $items[] = $indent.$prefix.$this->export($item, $depth + 1, is_string($itemKey) ? $itemKey : null).',';
            }

            return "[\n".implode("\n", $items)."\n".str_repeat('    ', $depth).']';
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => var_export($value, true),
        };
    }

    /**
     * @param  list<string>  $lines
     */
    protected function findKey(array $lines, string $key): ?int
    {
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, "    '{$key}' =>")) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    protected function findEnd(array $lines, int $start): int
    {
        if (! str_ends_with(rtrim($lines[$start]), '[')) {
            return $start;
        }

        for ($index = $start + 1, $count = count($lines); $index < $count; $index++) {
            if (rtrim($lines[$index]) === '    ],') {
                return $index;
            }
        }

        return $start;
    }
}
