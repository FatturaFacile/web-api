<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The same person prepared and is now approving or releasing a document that the company keeps segregated.
 */
final class SodDocumentConflictException extends AuthorizationException {}
