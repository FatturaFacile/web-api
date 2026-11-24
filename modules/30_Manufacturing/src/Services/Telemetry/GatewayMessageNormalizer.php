<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Telemetry;

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Models\MfgMachineSignalMap;

/**
 * Turns the messages that an edge gateway forwards for one industrial protocol into raw signals and decodes a raw signal
 * according to the mapping of a machine. The transport (OPC UA sessions and subscriptions, the MQTT broker connection,
 * Modbus TCP polling) always runs in the edge gateway: this contract only sees the messages the gateway relays.
 */
interface GatewayMessageNormalizer
{
    /**
     * The protocol key: opcua, mqtt or modbus.
     */
    public function protocol(): string;

    /**
     * The raw signals carried by one message.
     *
     * @param  array<string, mixed>  $message
     * @return list<RawSignal>
     *
     * @throws InvalidGatewayMessageException when the message does not have the shape of the protocol
     */
    public function extract(array $message, CarbonImmutable $receivedAt): array;

    /**
     * Whether the source key of a mapping selects a signal that arrived with the given key.
     */
    public function matches(string $incomingKey, string $configuredKey): bool;

    /**
     * The value of a raw signal as the mapping reads it (register decoding, value path), before scale and offset.
     */
    public function decode(RawSignal $signal, MfgMachineSignalMap $map): mixed;
}
