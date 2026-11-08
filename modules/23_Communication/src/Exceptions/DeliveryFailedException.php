<?php

declare(strict_types=1);

namespace Modules\Communication\Exceptions;

use RuntimeException;

/**
 * Raised when delivery failed.
 */
final class DeliveryFailedException extends RuntimeException {}
