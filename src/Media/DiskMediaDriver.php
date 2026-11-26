<?php

namespace Smith89k\PostMcp\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Smith89k\PostMcp\Contracts\MediaDriver;
use Smith89k\PostMcp\PostMcp;

/**
 * Stores images on a filesystem disk, such as "public" or "s3".
 */
class DiskMediaDriver implements MediaDriver
{
    public function __construct(protected PostMcp $postMcp) {}

    public function store(ImageFile $image, ?Model $post = null): StoredImage
    {
        $disk = Storage::disk((string) $this->postMcp->config('media.disk', 'public'));
        $directory = trim((string) $this->postMcp->config('media.directory', 'posts'), '/');
        $path = ltrim($directory.'/'.$image->filename, '/');

        $disk->put($path, $image->contents, ['visibility' => 'public', 'ContentType' => $image->mime]);

        return new StoredImage($disk->url($path), $path, $image->alt);
    }
}
