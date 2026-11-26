<?php

namespace Smith89k\PostMcp\Media;

use Illuminate\Database\Eloquent\Model;
use Smith89k\PostMcp\Contracts\MediaDriver;
use Smith89k\PostMcp\Exceptions\PostMcpException;
use Smith89k\PostMcp\PostMcp;

/**
 * Adds images to the post with spatie/laravel-medialibrary.
 */
class MediaLibraryDriver implements MediaDriver
{
    public function __construct(protected PostMcp $postMcp) {}

    public function store(ImageFile $image, ?Model $post = null): StoredImage
    {
        if ($post === null) {
            throw new PostMcpException('This site keeps images in the media library, which needs a post. Send post_id with the upload.');
        }

        if (! method_exists($post, 'addMediaFromString')) {
            throw new PostMcpException('The post model does not use spatie/laravel-medialibrary (InteractsWithMedia).');
        }

        $collection = (string) ($this->postMcp->config('media.attach_to') ?: $this->postMcp->config('media.collection', 'images'));

        $media = $post->addMediaFromString($image->contents)
            ->usingFileName($image->filename)
            ->withCustomProperties(array_filter(['alt' => $image->alt]))
            ->toMediaCollection($collection);

        return new StoredImage($media->getUrl(), $media->getPathRelativeToRoot(), $image->alt, $media->getKey());
    }
}
