<?php

declare(strict_types=1);

namespace App\Exceptions\Fiscal;

use InvalidArgumentException;

/**
 * The VAT number is not formally valid for its Member State: no network call is made.
 */
final class InvalidVatFormatException extends InvalidArgumentException {}
