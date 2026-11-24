<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Raised when something tries to change or delete a preserved record or document. It answers 409, like a legal hold.
 */
final class PreservationImmutableException extends ConflictHttpException {}
