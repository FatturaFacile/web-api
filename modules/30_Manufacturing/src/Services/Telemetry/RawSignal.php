<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Telemetry;

use Carbon\CarbonImmutable;

/**
 * One value read from a gateway message before any machine mapping: the key that identifies the source (OPC UA node id,
 * MQTT topic or Sparkplug topic/metric, Modbus `[unit:]function:address`), the raw value as the protocol delivered it
 * (a list of 16-bit register words for Modbus) and the time the value refers to.
 */
final readonly class RawSignal
{
    public function __construct(
        public string $sourceKey,
        public mixed $value,
        public CarbonImmutable $occurredAt,
    ) {}
}
