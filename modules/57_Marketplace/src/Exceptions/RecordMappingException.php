<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

use RuntimeException;

/**
 * A pulled record cannot be mapped (a required field is missing or malformed). Only that record fails: the run goes on.
 */
final class RecordMappingException extends RuntimeException {}
