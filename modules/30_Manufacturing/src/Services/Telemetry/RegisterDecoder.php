<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Telemetry;

/**
 * Decodes Modbus 16-bit register words into numbers. 32-bit types use two consecutive registers; `big` word order
 * puts the high word first (the common PLC layout), `little` swaps the words.
 */
final class RegisterDecoder
{
    /**
     * How many registers a data type occupies.
     */
    public static function width(string $dataType): int
    {
        return in_array($dataType, ['int32', 'uint32', 'float32'], true) ? 2 : 1;
    }

    /**
     * The value of the registers as int or float, null when the words are not enough or out of range.
     *
     * @param  list<int>  $words  registers starting at the mapped address
     */
    public static function decode(array $words, string $dataType, string $wordOrder = 'big'): int|float|null
    {
        if (count($words) < self::width($dataType)) {
            return null;
        }

        foreach (array_slice($words, 0, self::width($dataType)) as $word) {
            if ($word < 0 || $word > 0xFFFF) {
                return null;
            }
        }

        if (self::width($dataType) === 1) {
            $word = $words[0];

            return match ($dataType) {
                'int16' => $word >= 0x8000 ? $word - 0x10000 : $word,
                'bool' => $word !== 0 ? 1 : 0,
                default => $word,
            };
        }

        [$high, $low] = $wordOrder === 'little' ? [$words[1], $words[0]] : [$words[0], $words[1]];
        $unsigned = ($high << 16) | $low;

        return match ($dataType) {
            'int32' => $unsigned >= 0x80000000 ? $unsigned - 0x100000000 : $unsigned,
            'float32' => self::float($unsigned),
            default => $unsigned,
        };
    }

    private static function float(int $bits): float
    {
        $unpacked = unpack('G', pack('N', $bits));

        return is_array($unpacked) ? (float) $unpacked[1] : 0.0;
    }
}
