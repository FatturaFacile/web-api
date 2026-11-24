<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Exceptions;

use RuntimeException;

/**
 * Raised when a document cannot be signed or preserved.
 */
final class DocumentTrustException extends RuntimeException {}
