<?php

declare(strict_types=1);

namespace Modules\Communication\Contracts;

use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Exceptions\DeliveryFailedException;

interface DeliveryDriver
{
    /**
     * Stable identifier stored on the delivery log.
     */
    public function name(): string;

    /**
     * @param  array<string, string>  $credentials
     * @return array{status: string, reference: ?string} `sent`, or `simulated` when nothing left the application
     *
     * @throws DeliveryFailedException when the provider refuses the message or cannot be reached
     */
    public function send(DeliveryMessage $message, array $credentials): array;
}
