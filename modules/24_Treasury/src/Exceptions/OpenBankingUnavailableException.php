<?php

declare(strict_types=1);

namespace Modules\Treasury\Exceptions;

use RuntimeException;

final class OpenBankingUnavailableException extends RuntimeException
{
    public static function providerRequired(): self
    {
        return new self('Open banking needs a licensed provider or a bank agreement: no real connection is available.');
    }

    public static function simulatorInProduction(): self
    {
        return new self('The local open banking simulator does not run in production.');
    }
}
