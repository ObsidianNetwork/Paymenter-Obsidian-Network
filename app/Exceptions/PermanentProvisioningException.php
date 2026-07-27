<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A deterministic fulfillment contract violation that will not be repaired by
 * retrying the same queue payload.
 */
class PermanentProvisioningException extends RuntimeException {}
