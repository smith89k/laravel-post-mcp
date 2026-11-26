<?php

namespace Smith89k\PostMcp\Content;

use Illuminate\Support\Str;
use Smith89k\PostMcp\Contracts\ContentConverter;

/**
 * Lets Claude write Markdown for a site that stores HTML.
 */
class MarkdownToHtmlConverter implements ContentConverter
{
    public function convert(mixed $content, string $fromFormat, string $toFormat): mixed
    {
        if (! is_string($content) || $fromFormat !== 'markdown' || $toFormat !== 'html') {
            return $content;
        }

        return Str::markdown($content, ['html_input' => 'allow', 'allow_unsafe_links' => false]);
    }
}
