<?php

namespace Smith89k\PostMcp\Contracts;

use Illuminate\Database\Eloquent\Model;
use Smith89k\PostMcp\Media\ImageFile;
use Smith89k\PostMcp\Media\StoredImage;

/**
 * Stores an uploaded image and returns where it can be found.
 */
interface MediaDriver
{
    public function store(ImageFile $image, ?Model $post = null): StoredImage;
}
