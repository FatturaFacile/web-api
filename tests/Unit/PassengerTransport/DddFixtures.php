<?php

declare(strict_types=1);

/*
| Builders of tachograph driver card files (.DDD) for the parser and the upload tests. Required once from each test file.
*/

const PTR_DRIVING = 3;
const PTR_WORK = 2;
const PTR_REST = 0;

/**
 * One elementary file of a driver card download: file id, data appendix (0) and length, then the data.
 */
function ptrEf(int $fid, string $data): string
{
    return pack('nCn', $fid, 0, strlen($data)).$data;
}

function ptrUtc(string $date): int
{
    return (new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
}

/**
 * EF_Identification: member state, 16 characters of card number, authority, issue date, validity start and expiry.
 */
function ptrIdentification(string $cardNumber, string $expiry): string
{
    return chr(0x1E).str_pad($cardNumber, 16).str_repeat("\0", 36).pack('N', ptrUtc('2024-01-01')).pack('N', ptrUtc('2024-01-01')).pack('N', ptrUtc($expiry)).str_repeat("\0", 78);
}

/**
 * EF_Driver_Activity_Data: pointers to the oldest and newest daily record, then the daily records (activity change words: activity in
 * bits 11-12, minutes since midnight in bits 0-10).
 *
 * @param  array<string, list<array{0: int, 1: int}>>  $days  date => list of [activity, minute]
 */
function ptrActivities(array $days): string
{
    $records = [];
    $previous = 0;

    foreach ($days as $date => $changes) {
        $body = '';

        foreach ($changes as [$activity, $minute]) {
            $body .= pack('n', ($activity << 11) | $minute);
        }

        $length = 12 + strlen($body);
        $records[] = pack('nnNnn', $previous, $length, ptrUtc($date), 1, 100).$body;
        $previous = $length;
    }

    $newest = strlen(implode('', array_slice($records, 0, -1)));

    return pack('nn', 0, $newest).implode('', $records);
}

/**
 * @param  array<string, list<array{0: int, 1: int}>>  $days
 */
function ptrDriverCardFile(array $days, string $cardNumber = '1000012345678901', string $expiry = '2029-01-01'): string
{
    return ptrEf(0x0002, str_repeat("\0", 25)).ptrEf(0x0520, ptrIdentification($cardNumber, $expiry)).ptrEf(0x0504, ptrActivities($days));
}
