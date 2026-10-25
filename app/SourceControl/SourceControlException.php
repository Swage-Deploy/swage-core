<?php

namespace App\SourceControl;

use RuntimeException;

/**
 * A provider refused a request or could not be reached. The message is shown to users, so it never holds credentials.
 */
class SourceControlException extends RuntimeException {}
