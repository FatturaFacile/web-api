<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Exceptions;

use InvalidArgumentException;

final class InvalidLocaleException extends InvalidArgumentException
{
    public static function unsupported(string $value): self
    {
        return new self("Unsupported locale [{$value}].");
    }
}
