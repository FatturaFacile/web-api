<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\Exceptions;

use RuntimeException;

/**
 * A conservator refused the package or could not be reached.
 */
final class ConnectorException extends RuntimeException {}
