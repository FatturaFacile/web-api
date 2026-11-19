<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\Exceptions;

/**
 * A sealed record or an audit entry cannot be changed or deleted.
 */
final class PreservationImmutableException extends PreservationException {}
