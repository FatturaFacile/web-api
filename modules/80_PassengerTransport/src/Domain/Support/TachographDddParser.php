<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Support;

use DateTimeImmutable;
use DateTimeZone;
use Modules\PassengerTransport\Domain\Enums\DddFileType;
use Modules\PassengerTransport\Exceptions\PassengerTransportException;

/**
 * Reads a downloaded tachograph file (.DDD). Driver card files (Annex 1B layout of the first generation, the same elementary files
 * 0x0520 and 0x0504 are present in the first application of the second generation) are decoded into the card number, its expiry,
 * the driving per day and the infringements; vehicle unit files (TREP 0x76) are recognised and archived with their hash only.
 *
 * Result shape: type, card_number, card_expires_on, period_start, period_end, days (date, driving_minutes, work_minutes, longest_rest_minutes,
 * continuous_driving_minutes_at_end) and infringements (code, date, minutes).
 */
final class TachographDddParser
{
    private const int MAX_DAYS = 400;

    private const int CONTINUOUS_LIMIT = 270;

    private const int DAILY_EXTENDED_LIMIT = 600;

    private const int WEEKLY_LIMIT = 3360;

    /**
     * @return array{type: DddFileType, card_number: string|null, card_expires_on: string|null, period_start: string|null, period_end: string|null, days: list<array{date: string, driving_minutes: int, work_minutes: int, longest_rest_minutes: int, continuous_driving_minutes_at_end: int, max_continuous_driving_minutes: int}>, infringements: list<array{code: string, date: string, minutes: int}>}
     */
    public function parse(string $binary): array
    {
        if (strlen($binary) < 8) {
            throw new PassengerTransportException('ddd_file_invalid', 422);
        }

        if ($binary[0] === "\x76") {
            return ['type' => DddFileType::VehicleUnit, 'card_number' => null, 'card_expires_on' => null, 'period_start' => null, 'period_end' => null, 'days' => [], 'infringements' => []];
        }

        $files = $this->elementaryFiles($binary);

        if (! isset($files[0x0520], $files[0x0504])) {
            throw new PassengerTransportException('ddd_file_unrecognised', 422);
        }

        $identification = $files[0x0520];
        $cardNumber = trim(substr($identification, 1, 16));
        $expiry = strlen($identification) >= 65 ? $this->timeReal((int) current(unpack('N', substr($identification, 61, 4)))) : null;
        $days = $this->activityDays($files[0x0504]);
        $infringements = $this->infringements($days);

        return [
            'type' => DddFileType::DriverCard,
            'card_number' => $cardNumber === '' ? null : $cardNumber,
            'card_expires_on' => $expiry?->format('Y-m-d'),
            'period_start' => $days === [] ? null : $days[0]['date'],
            'period_end' => $days === [] ? null : $days[count($days) - 1]['date'],
            'days' => $days,
            'infringements' => $infringements,
        ];
    }

    /**
     * @return array<int, string> data of every elementary file (signatures skipped), by file id
     */
    private function elementaryFiles(string $binary): array
    {
        $files = [];
        $offset = 0;
        $length = strlen($binary);

        while ($offset + 5 <= $length) {
            $header = unpack('nfid/Ctype/nlen', substr($binary, $offset, 5));
            $offset += 5;

            if ($header['len'] > $length - $offset) {
                break;
            }

            if ($header['type'] === 0) {
                $files[$header['fid']] = substr($binary, $offset, $header['len']);
            }

            $offset += $header['len'];
        }

        return $files;
    }

    /**
     * @return list<array{date: string, driving_minutes: int, work_minutes: int, longest_rest_minutes: int, continuous_driving_minutes_at_end: int, max_continuous_driving_minutes: int}>
     */
    private function activityDays(string $data): array
    {
        if (strlen($data) < 4 + 12) {
            return [];
        }

        $pointers = unpack('noldest/nnewest', substr($data, 0, 4));
        $ring = substr($data, 4);
        $size = strlen($ring);
        $position = $pointers['oldest'] % $size;
        $newest = $pointers['newest'] % $size;
        $days = [];

        for ($count = 0; $count < self::MAX_DAYS; $count++) {
            $head = $this->circular($ring, $position, 12);
            $header = unpack('nprevious/nlength/Ndate/npresence/ndistance', $head);

            if ($header['length'] < 12 || $header['length'] > $size) {
                break;
            }

            $changes = [];

            for ($index = 12; $index + 2 <= $header['length']; $index += 2) {
                $word = (int) current(unpack('n', $this->circular($ring, $position + $index, 2)));
                $changes[] = ['activity' => ($word >> 11) & 0x3, 'minute' => $word & 0x7FF];
            }

            $days[] = $this->day($this->timeReal($header['date']), $changes);

            if ($position === $newest) {
                break;
            }

            $position = ($position + $header['length']) % $size;
        }

        usort($days, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $days;
    }

    /**
     * @param  list<array{activity: int, minute: int}>  $changes
     * @return array{date: string, driving_minutes: int, work_minutes: int, longest_rest_minutes: int, continuous_driving_minutes_at_end: int, max_continuous_driving_minutes: int}
     */
    private function day(DateTimeImmutable $date, array $changes): array
    {
        usort($changes, static fn (array $a, array $b): int => $a['minute'] <=> $b['minute']);
        $driving = 0;
        $work = 0;
        $longestRest = 0;
        $continuous = 0;
        $maxContinuous = 0;
        $splitBreak = false;

        foreach ($changes as $index => $change) {
            $end = $changes[$index + 1]['minute'] ?? 1440;
            $minutes = max(0, $end - $change['minute']);

            if ($change['activity'] === 3) {
                $driving += $minutes;
                $work += $minutes;
                $continuous += $minutes;
                $maxContinuous = max($maxContinuous, $continuous);

                continue;
            }

            if ($change['activity'] === 2) {
                $work += $minutes;
            }

            if ($change['activity'] === 0) {
                $longestRest = max($longestRest, $minutes);
            }

            if ($minutes >= 45) {
                $continuous = 0;
                $splitBreak = false;
            } elseif ($minutes >= 30 && $splitBreak) {
                $continuous = 0;
                $splitBreak = false;
            } elseif ($minutes >= 15 && $change['activity'] === 0) {
                $splitBreak = true;
            }
        }

        return ['date' => $date->format('Y-m-d'), 'driving_minutes' => $driving, 'work_minutes' => $work, 'longest_rest_minutes' => $longestRest, 'continuous_driving_minutes_at_end' => $continuous, 'max_continuous_driving_minutes' => $maxContinuous];
    }

    /**
     * @param  list<array{date: string, driving_minutes: int, work_minutes: int, longest_rest_minutes: int, continuous_driving_minutes_at_end: int, max_continuous_driving_minutes: int}>  $days
     * @return list<array{code: string, date: string, minutes: int}>
     */
    private function infringements(array $days): array
    {
        $infringements = [];
        $weeks = [];

        foreach ($days as $day) {
            if ($day['driving_minutes'] > self::DAILY_EXTENDED_LIMIT) {
                $infringements[] = ['code' => 'daily_driving_exceeded', 'date' => $day['date'], 'minutes' => $day['driving_minutes']];
            }

            if ($day['max_continuous_driving_minutes'] > self::CONTINUOUS_LIMIT) {
                $infringements[] = ['code' => 'continuous_driving_exceeded', 'date' => $day['date'], 'minutes' => $day['max_continuous_driving_minutes']];
            }

            $week = (new DateTimeImmutable($day['date']))->format('o-W');
            $weeks[$week] = ($weeks[$week] ?? 0) + $day['driving_minutes'];
        }

        foreach ($days as $day) {
            $week = (new DateTimeImmutable($day['date']))->format('o-W');

            if (($weeks[$week] ?? 0) > self::WEEKLY_LIMIT) {
                $infringements[] = ['code' => 'weekly_driving_exceeded', 'date' => $day['date'], 'minutes' => $weeks[$week]];
                unset($weeks[$week]);
            }
        }

        return $infringements;
    }

    private function circular(string $ring, int $position, int $length): string
    {
        $size = strlen($ring);
        $position %= $size;

        if ($position + $length <= $size) {
            return substr($ring, $position, $length);
        }

        return substr($ring, $position).substr($ring, 0, $length - ($size - $position));
    }

    private function timeReal(int $seconds): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$seconds))->setTimezone(new DateTimeZone('UTC'));
    }
}
