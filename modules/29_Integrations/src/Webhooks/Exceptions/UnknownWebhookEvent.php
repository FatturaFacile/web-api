<?php

declare(strict_types=1);

namespace Modules\Integrations\Webhooks\Exceptions;

use RuntimeException;

final class UnknownWebhookEvent extends RuntimeException
{
    public function __construct(public readonly string $eventName)
    {
        parent::__construct("The event [{$eventName}] is not in the webhook catalogue.");
    }
}
