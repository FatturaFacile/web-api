<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Telemetry;

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Models\MfgMachineSignalMap;

/**
 * Modbus reads polled by a gateway and relayed as JSON blocks:
 * `{"unit_id": 1, "timestamp": "...", "blocks": [{"function": "holding", "address": 100, "registers": [1, 2]},
 * {"function": "coil", "address": 5, "values": [true, false]}]}`.
 * `function` is holding, input, coil or discrete; a register block holds at most 125 words, a bit block 2000 values (the
 * limits of the protocol). Each register or bit is one signal keyed `[unit:]function:address` (the unit prefix is optional
 * in the mapping, so several PLCs behind one gateway can be told apart by `1:holding:100`). The mapping gives the data type
 * (bool, int16, uint16, int32, uint32, float32) and the word order of the 32-bit types.
 */
final class ModbusNormalizer extends AbstractGatewayNormalizer
{
    private const array RegisterFunctions = ['holding', 'input'];

    private const array BitFunctions = ['coil', 'discrete'];

    /**
     * The protocol key.
     */
    public function protocol(): string
    {
        return 'modbus';
    }

    /**
     * One raw signal per register or bit of every block.
     *
     * @param  array<string, mixed>  $message
     * @return list<RawSignal>
     */
    public function extract(array $message, CarbonImmutable $receivedAt): array
    {
        if (! isset($message['blocks']) || ! is_array($message['blocks']) || $message['blocks'] === []) {
            throw new InvalidGatewayMessageException('The message has no "blocks".');
        }

        $time = self::time($message['timestamp'] ?? null, $receivedAt);
        $unit = isset($message['unit_id']) && is_numeric($message['unit_id']) ? (int) $message['unit_id'] : null;
        $signals = [];

        foreach ($message['blocks'] as $block) {
            if (! is_array($block)) {
                throw new InvalidGatewayMessageException('A block must be an object.');
            }

            array_push($signals, ...$this->block($block, $unit, $time));
        }

        return $signals;
    }

    /**
     * A configured key without the unit selects the signal of every unit.
     */
    public function matches(string $incomingKey, string $configuredKey): bool
    {
        if ($incomingKey === $configuredKey) {
            return true;
        }

        $parts = explode(':', $incomingKey);

        return count($parts) === 3 && implode(':', array_slice($parts, 1)) === $configuredKey;
    }

    /**
     * Decodes the registers with the data type and word order of the mapping.
     */
    public function decode(RawSignal $signal, MfgMachineSignalMap $map): mixed
    {
        /** @var list<int> $words */
        $words = is_array($signal->value) ? array_values($signal->value) : [];

        return RegisterDecoder::decode($words, $map->data_type ?? 'uint16', $map->word_order);
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return list<RawSignal>
     */
    private function block(array $block, ?int $unit, CarbonImmutable $time): array
    {
        $function = $block['function'] ?? null;
        $address = $block['address'] ?? null;

        if (! is_string($function) || ! in_array($function, [...self::RegisterFunctions, ...self::BitFunctions], true)) {
            throw new InvalidGatewayMessageException('A block needs a "function" of holding, input, coil or discrete.');
        }

        if (! is_int($address) || $address < 0 || $address > 65535) {
            throw new InvalidGatewayMessageException('A block needs an integer "address" from 0 to 65535.');
        }

        $isRegister = in_array($function, self::RegisterFunctions, true);
        $values = $block[$isRegister ? 'registers' : 'values'] ?? null;
        $limit = $isRegister ? 125 : 2000;

        if (! is_array($values) || $values === [] || count($values) > $limit) {
            throw new InvalidGatewayMessageException(sprintf('A %s block needs from 1 to %d values.', $function, $limit));
        }

        $values = array_values($values);
        $signals = [];

        foreach ($values as $index => $value) {
            $words = $isRegister ? array_map(static fn (mixed $word): int => is_int($word) ? $word : -1, array_slice($values, $index, 2)) : [$value ? 1 : 0];
            $key = $function.':'.($address + $index);

            $signals[] = new RawSignal($unit === null ? $key : $unit.':'.$key, $words, $time);
        }

        return $signals;
    }
}
