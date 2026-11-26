<?php

namespace Smith89k\PostMcp\Media;

/**
 * An image that passed the type and size checks, ready to be stored.
 */
class ImageFile
{
    public function __construct(
        public string $contents,
        public string $mime,
        public string $extension,
        public string $filename,
        public ?string $alt = null,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }
}
