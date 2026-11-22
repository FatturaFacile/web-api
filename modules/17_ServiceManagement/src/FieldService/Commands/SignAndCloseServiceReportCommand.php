<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

use Carbon\CarbonImmutable;

/**
 * Signs the digital service report of a work order and closes the work order. Sent by the web app and by the offline
 * mobile sync alike; `clientUuid` makes it idempotent.
 */
final readonly class SignAndCloseServiceReportCommand
{
    /**
     * @param  list<array{item: string, result: string, notes?: string|null}>  $checklist
     * @param  list<array{path?: string, name?: string, taken_at?: string}>  $photos
     * @param  list<string>  $photoIds  ids of photos uploaded to the work order, referenced by the report
     */
    public function __construct(
        public string $workOrderId,
        public string $workPerformedSummary,
        public string $signerName,
        public string $signatureSvg,
        public CarbonImmutable $signedAt,
        public ?string $clientUuid = null,
        public ?string $signerRole = null,
        public array $checklist = [],
        public string $laborHours = '0',
        public string $travelHours = '0',
        public string $travelKm = '0',
        public bool $applyCalloutFee = false,
        public ?string $meterReadingHours = null,
        public ?int $meterReadingCycles = null,
        public ?float $gpsLatitude = null,
        public ?float $gpsLongitude = null,
        public array $photos = [],
        public array $photoIds = [],
        public string $locale = 'it-IT',
        public ?string $actorUserId = null,
    ) {}
}
