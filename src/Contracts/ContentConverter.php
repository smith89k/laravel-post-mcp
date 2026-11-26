<?php

namespace Smith89k\PostMcp\Contracts;

/**
 * Turns the content Claude writes into the format the site stores.
 */
interface ContentConverter
{
    public function convert(mixed $content, string $fromFormat, string $toFormat): mixed;
}
