<?php

namespace Smith89k\PostMcp\Tests\Fixtures\Models;

enum PostState: string
{
    case Hidden = 'hidden';
    case Live = 'live';
    case Queued = 'queued';
}
