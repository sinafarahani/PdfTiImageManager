<?php

namespace App\Actions\Converter\Pipeline;

use RuntimeException;

/**
 * There is no room to convert a content right now. Always worth retrying: the drive may have space
 * again by the time the content comes round.
 */
class WorkspaceUnavailable extends RuntimeException {}
