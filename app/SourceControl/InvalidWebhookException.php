<?php

namespace App\SourceControl;

use RuntimeException;

/**
 * A webhook delivery could not be verified as coming from the provider with the site's secret.
 */
class InvalidWebhookException extends RuntimeException {}
