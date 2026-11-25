<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

/**
 * Cleans the raw reads of a UHF reader (Impinj, Zebra, CAEN) at a tunnel gate, deterministically: the same reads always give the
 * same accepted list, whatever order the reader sent them in.
 *
 * A read is rejected as `invalid_epc` (not hexadecimal, odd length, outside the length bounds: a spurious decode),
 * `foreign_antenna` (heard by an antenna that is not the gate's: the next gate or the aisle) or `out_of_range` (weaker than the
 * threshold: a tag of the next cage). The remaining reads of one tag collapse to one accepted tag with its strongest RSSI; each
 * extra read is reported as `duplicate`.
 */
final class TagReadFilter
{
    public const string InvalidEpc = 'invalid_epc';

    public const string ForeignAntenna = 'foreign_antenna';

    public const string OutOfRange = 'out_of_range';

    public const string Duplicate = 'duplicate';

    /**
     * The canonical form of an EPC: no 0x prefix, no separators, upper case. Null when it is not a valid hexadecimal EPC.
     */
    public static function normalizeEpc(string $epc): ?string
    {
        $clean = strtoupper((string) preg_replace('/[\s:\-_]+/', '', trim($epc)));

        if (str_starts_with($clean, '0X')) {
            $clean = substr($clean, 2);
        }

        $length = strlen($clean);
        $min = (int) config('industrial_laundry.rfid.min_epc_hex_length', 8);
        $max = (int) config('industrial_laundry.rfid.max_epc_hex_length', 64);

        if ($length < $min || $length > $max || $length % 2 !== 0 || preg_match('/^[0-9A-F]+$/', $clean) !== 1) {
            return null;
        }

        return $clean;
    }

    /**
     * @param  list<array<string, mixed>>  $reads  Each read: epc, optional rssi (dBm), antenna_id, seen_at.
     * @return array{accepted: list<array{epc: string, rssi: float|null, reads: int}>, rejected: list<array{epc: string, reason: string, rssi: float|null}>}
     */
    public function filter(array $reads, string $gateAntennaId): array
    {
        $minRssi = (float) config('industrial_laundry.rfid.min_rssi_dbm', -75);
        $accepted = [];
        $rejected = [];

        foreach ($reads as $read) {
            $raw = (string) ($read['epc'] ?? '');
            $rssi = isset($read['rssi']) && is_numeric($read['rssi']) ? (float) $read['rssi'] : null;
            $epc = self::normalizeEpc($raw);

            if ($epc === null) {
                $rejected[] = ['epc' => $raw, 'reason' => self::InvalidEpc, 'rssi' => $rssi];

                continue;
            }

            $antenna = isset($read['antenna_id']) ? (string) $read['antenna_id'] : null;

            if ($antenna !== null && $antenna !== '' && $antenna !== $gateAntennaId && ! str_starts_with($antenna, $gateAntennaId)) {
                $rejected[] = ['epc' => $epc, 'reason' => self::ForeignAntenna, 'rssi' => $rssi];

                continue;
            }

            if ($rssi !== null && $rssi < $minRssi) {
                $rejected[] = ['epc' => $epc, 'reason' => self::OutOfRange, 'rssi' => $rssi];

                continue;
            }

            if (isset($accepted[$epc])) {
                $rejected[] = ['epc' => $epc, 'reason' => self::Duplicate, 'rssi' => $rssi];
                $accepted[$epc]['reads']++;
                $accepted[$epc]['rssi'] = $rssi !== null && ($accepted[$epc]['rssi'] === null || $rssi > $accepted[$epc]['rssi']) ? $rssi : $accepted[$epc]['rssi'];

                continue;
            }

            $accepted[$epc] = ['epc' => $epc, 'rssi' => $rssi, 'reads' => 1];
        }

        // A tag whose only reads were weak is out of range, not accepted: those were rejected above and never reach $accepted.
        ksort($accepted);

        return ['accepted' => array_values($accepted), 'rejected' => $this->ordered($rejected)];
    }

    /**
     * @param  list<array{epc: string, reason: string, rssi: float|null}>  $rejected
     * @return list<array{epc: string, reason: string, rssi: float|null}>
     */
    private function ordered(array $rejected): array
    {
        usort($rejected, static fn (array $left, array $right): int => [$left['epc'], $left['reason']] <=> [$right['epc'], $right['reason']]);

        return $rejected;
    }
}
