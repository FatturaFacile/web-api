<?php

declare(strict_types=1);

namespace Modules\Documents\Exceptions;

use RuntimeException;

/**
 * Raised when duplicate document.
 */
final class DuplicateDocumentException extends RuntimeException
{
    /**
     * Inject the collaborators.
     */
    public function __construct(public readonly string $existingDocumentId, public readonly string $hash)
    {
        parent::__construct("A file with the same hash already exists in document {$existingDocumentId}.");
    }
}
