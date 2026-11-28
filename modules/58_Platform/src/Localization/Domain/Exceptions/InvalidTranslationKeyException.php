<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Exceptions;

use InvalidArgumentException;

final class InvalidTranslationKeyException extends InvalidArgumentException
{
    public static function malformed(string $key): self
    {
        return new self("Malformed translation key [{$key}]: expected group.segment[.segment].");
    }
}
