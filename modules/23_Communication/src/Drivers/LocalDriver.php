<?php

declare(strict_types=1);

namespace Modules\Communication\Drivers;

use Illuminate\Support\Str;
use Modules\Communication\Contracts\DeliveryDriver;
use Modules\Communication\DTOs\DeliveryMessage;

/**
 * Records the delivery without sending anything, for installations with no provider configured.
 */
final class LocalDriver implements DeliveryDriver
{
    /**
     * Driver or resolver name.
     */
    public function name(): string
    {
        return 'local';
    }

    /**
     * Send the message.
     */
    public function send(DeliveryMessage $message, array $credentials): array
    {
        return ['status' => 'simulated', 'reference' => 'LOCAL-'.Str::uuid()];
    }
}
