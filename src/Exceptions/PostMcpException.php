<?php

namespace Smith89k\PostMcp\Exceptions;

use RuntimeException;

/**
 * A problem Claude can fix and retry. The message is shown to Claude as is.
 */
class PostMcpException extends RuntimeException {}
