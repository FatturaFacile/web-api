<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Exceptions;

use RuntimeException;

/**
 * A rule condition or a formula that cannot be parsed or evaluated (syntax error, unknown function, division by zero, a
 * value that is not a number). The message is meant for the person who writes the expression.
 */
final class ExpressionException extends RuntimeException {}
