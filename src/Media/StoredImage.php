<?php

namespace Smith89k\PostMcp\Media;

/**
 * Where an uploaded image ended up, with snippets Claude can paste into the body.
 */
class StoredImage
{
    public function __construct(
        public string $url,
        public ?string $path = null,
        public ?string $alt = null,
        public int|string|null $mediaId = null,
    ) {}

    /**
     * @return array{url: string, path: string|null, media_id: int|string|null, markdown: string, html: string}
     */
    public function toArray(): array
    {
        $alt = (string) $this->alt;

        return [
            'url' => $this->url,
            'path' => $this->path,
            'media_id' => $this->mediaId,
            'markdown' => '!['.str_replace(['[', ']'], '', $alt).']('.$this->url.')',
            'html' => '<img src="'.e($this->url).'" alt="'.e($alt).'">',
        ];
    }
}
