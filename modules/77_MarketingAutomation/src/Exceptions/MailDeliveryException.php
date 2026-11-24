<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Exceptions;

use RuntimeException;

/**
 * A provider refused or could not take the message. Permanent failures (invalid address) are hard bounces.
 */
final class MailDeliveryException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }
}
