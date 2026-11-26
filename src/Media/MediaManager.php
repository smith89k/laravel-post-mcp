<?php

namespace Smith89k\PostMcp\Media;

use Illuminate\Contracts\Container\Container;
use Smith89k\PostMcp\Contracts\MediaDriver;
use Smith89k\PostMcp\Exceptions\PostMcpException;
use Smith89k\PostMcp\PostMcp;

/**
 * Picks the media driver named in the config.
 */
class MediaManager
{
    public function __construct(
        protected PostMcp $postMcp,
        protected Container $container,
    ) {}

    public function enabled(): bool
    {
        return $this->postMcp->mediaDriver() !== 'none';
    }

    public function driver(): MediaDriver
    {
        $name = $this->postMcp->mediaDriver();

        $driver = match ($name) {
            'none' => throw new PostMcpException('Image uploads are turned off on this site.'),
            'disk' => $this->container->make(DiskMediaDriver::class),
            'medialibrary' => $this->container->make(MediaLibraryDriver::class),
            default => class_exists($name) ? $this->container->make($name) : null,
        };

        if (! $driver instanceof MediaDriver) {
            throw new PostMcpException("The media driver \"{$name}\" must be none, disk, medialibrary or a class implementing ".MediaDriver::class.'.');
        }

        return $driver;
    }
}
