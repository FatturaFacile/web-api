<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Exceptions;

use DomainException;

/**
 * A violation of an HSE business rule; the controller turns it into a 422.
 */
class HseException extends DomainException {}
