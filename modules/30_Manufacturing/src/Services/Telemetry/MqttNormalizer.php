<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Telemetry;

use Carbon\CarbonImmutable;

/**
 * MQTT publications relayed by an MQTT bridge or gateway: `{"topic": "plant/press1/state", "payload": ..., "timestamp":
 * ...}` where `payload` is a scalar, a JSON object or a string that holds either (the broker delivers bytes).
 *
 * Sparkplug B: when the topic is a Sparkplug topic (`spBv1.0/{group}/{NDATA|DDATA|NBIRTH|DBIRTH}/{node}[/{device}]`) and
 * the payload, decoded from protobuf by the gateway to its JSON form, has a `metrics` list, every metric is one signal
 * keyed `{topic}/{metric name}` and carries its own timestamp (epoch milliseconds).
 *
 * The source key of a mapping is the topic; `+` matches one level and a trailing `#` matches the rest, like in MQTT
 * subscriptions. A JSON payload is read at the `value_path` of the mapping (dotted path, for example `data.temp`).
 */
final class MqttNormalizer extends AbstractGatewayNormalizer
{
    private const string SparkplugPrefix = 'spBv1.0/';

    /**
     * The protocol key.
     */
    public function protocol(): string
    {
        return 'mqtt';
    }

    /**
     * The raw signals of one publication.
     *
     * @param  array<string, mixed>  $message
     * @return list<RawSignal>
     */
    public function extract(array $message, CarbonImmutable $receivedAt): array
    {
        $topic = self::requireString($message, 'topic');

        if (str_contains($topic, '+') || str_contains($topic, '#')) {
            throw new InvalidGatewayMessageException('A published topic cannot contain wildcards.');
        }

        if (! array_key_exists('payload', $message)) {
            throw new InvalidGatewayMessageException('The message has no "payload".');
        }

        $payload = self::payload($message['payload']);
        $time = self::time($message['timestamp'] ?? (is_array($payload) ? ($payload['timestamp'] ?? null) : null), $receivedAt);

        if (str_starts_with($topic, self::SparkplugPrefix) && is_array($payload) && isset($payload['metrics']) && is_array($payload['metrics'])) {
            return $this->sparkplug($topic, $payload['metrics'], $time);
        }

        return [new RawSignal($topic, $payload, $time)];
    }

    /**
     * MQTT topic filter semantics: `+` is one level, a final `#` is any number of levels.
     */
    public function matches(string $incomingKey, string $configuredKey): bool
    {
        if ($incomingKey === $configuredKey) {
            return true;
        }

        $filter = explode('/', $configuredKey);
        $topic = explode('/', $incomingKey);

        foreach ($filter as $index => $level) {
            if ($level === '#') {
                return $index === count($filter) - 1;
            }

            if (! array_key_exists($index, $topic) || ($level !== '+' && $level !== $topic[$index])) {
                return false;
            }
        }

        return count($filter) === count($topic);
    }

    /**
     * @param  array<array-key, mixed>  $metrics
     * @return list<RawSignal>
     */
    private function sparkplug(string $topic, array $metrics, CarbonImmutable $fallback): array
    {
        $signals = [];

        foreach ($metrics as $metric) {
            if (! is_array($metric) || ! isset($metric['name']) || ! is_string($metric['name']) || ! array_key_exists('value', $metric)) {
                throw new InvalidGatewayMessageException('A Sparkplug metric needs a "name" and a "value".');
            }

            $signals[] = new RawSignal(rtrim($topic, '/').'/'.$metric['name'], $metric['value'], self::time($metric['timestamp'] ?? null, $fallback));
        }

        return $signals;
    }

    private static function payload(mixed $payload): mixed
    {
        if (! is_string($payload)) {
            return $payload;
        }

        $trimmed = trim($payload);

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $trimmed;
    }
}
