<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The request was already decided, or its user has not confirmed the email address yet.
 */
class TenantAccessRequestNotDecidableException extends RuntimeException {}
