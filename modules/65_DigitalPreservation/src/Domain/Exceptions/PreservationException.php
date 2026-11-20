<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\Exceptions;

use RuntimeException;

/**
 * A preservation rule was violated (wrong state, nothing to preserve, immutable record, unsupported format).
 */
class PreservationException extends RuntimeException {}
