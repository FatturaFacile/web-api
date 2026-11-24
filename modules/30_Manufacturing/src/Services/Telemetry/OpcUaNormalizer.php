<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Telemetry;

use Carbon\CarbonImmutable;

/**
 * OPC UA values forwarded by a gateway as JSON. Two shapes are read:
 * - a monitored item notification: `{"nodeId": "ns=2;s=Press1.State", "value": 2, "sourceTimestamp": "..."}` where
 *   `value` is a scalar, a DataValue (`{"Value": 2, "SourceTimestamp": "..."}`) or a Variant (`{"Type": 6, "Body": 2}`);
 * - an OPC UA PubSub JSON NetworkMessage (OPC 10000-14 section 7.2.3): `{"Messages": [{"Timestamp": "...", "Payload":
 *   {"ns=2;s=Press1.State": {"Value": 2, "SourceTimestamp": "..."}, "ns=2;s=Press1.Count": 120}}]}`.
 * The node id of the mapping is the source key (for example `ns=2;s=Press1.State`).
 */
final class OpcUaNormalizer extends AbstractGatewayNormalizer
{
    /**
     * The protocol key.
     */
    public function protocol(): string
    {
        return 'opcua';
    }

    /**
     * The raw signals of one notification or PubSub network message.
     *
     * @param  array<string, mixed>  $message
     * @return list<RawSignal>
     */
    public function extract(array $message, CarbonImmutable $receivedAt): array
    {
        if (isset($message['Messages']) && is_array($message['Messages'])) {
            return $this->pubSub($message['Messages'], $receivedAt);
        }

        $nodeId = self::requireString($message, 'nodeId');
        $value = $message['value'] ?? $message['Value'] ?? null;

        if ($value === null) {
            throw new InvalidGatewayMessageException('The message has no "value".');
        }

        $time = $message['sourceTimestamp'] ?? $message['SourceTimestamp'] ?? $message['timestamp'] ?? null;

        return [$this->signal($nodeId, $value, self::time($time, $receivedAt))];
    }

    /**
     * @param  array<array-key, mixed>  $messages
     * @return list<RawSignal>
     */
    private function pubSub(array $messages, CarbonImmutable $receivedAt): array
    {
        $signals = [];

        foreach ($messages as $dataSet) {
            if (! is_array($dataSet) || ! isset($dataSet['Payload']) || ! is_array($dataSet['Payload'])) {
                throw new InvalidGatewayMessageException('A DataSetMessage has no "Payload".');
            }

            $dataSetTime = self::time($dataSet['Timestamp'] ?? null, $receivedAt);

            foreach ($dataSet['Payload'] as $nodeId => $value) {
                $signals[] = $this->signal((string) $nodeId, $value, $dataSetTime);
            }
        }

        return $signals;
    }

    private function signal(string $nodeId, mixed $value, CarbonImmutable $time): RawSignal
    {
        if (is_array($value) && (array_key_exists('Value', $value) || array_key_exists('Body', $value))) {
            $time = self::time($value['SourceTimestamp'] ?? null, $time);
            $value = $value['Value'] ?? $value['Body'];

            if (is_array($value) && array_key_exists('Body', $value)) {
                $value = $value['Body'];
            }
        }

        return new RawSignal($nodeId, $value, $time);
    }
}
