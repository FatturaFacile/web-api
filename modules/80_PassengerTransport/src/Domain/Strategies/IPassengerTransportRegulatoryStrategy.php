<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Strategies;

use Modules\PassengerTransport\Domain\Enums\DrivingRegime;
use Modules\PassengerTransport\Domain\Enums\Jurisdiction;
use Modules\PassengerTransport\Domain\ValueObjects\ComplianceIssue;
use Modules\PassengerTransport\Domain\ValueObjects\DriverCounters;
use Modules\PassengerTransport\Domain\ValueObjects\TripPlan;
use Modules\PassengerTransport\Domain\ValueObjects\VatBreakdown;
use Modules\PassengerTransport\Domain\ValueObjects\VatInput;

/**
 * Regulatory and fiscal rules of passenger transport of one fiscal country: driving and rest time, VAT of the (cross-border) fare
 * and the mandatory content of the NCC service sheet. Resolved from the fiscal country of the company.
 */
interface IPassengerTransportRegulatoryStrategy
{
    public function jurisdiction(): Jurisdiction;

    /**
     * The regime of the vehicle: OLR 1 / EC 561 above 3.5 t or more than nine seats (driver included), else OLR 2.
     */
    public function drivingRegime(float $grossWeightTons, int $passengerCapacity): DrivingRegime;

    /**
     * @return list<ComplianceIssue>
     */
    public function evaluateDriving(DrivingRegime $regime, DriverCounters $counters, TripPlan $trip, bool $tripTouchesCh): array;

    public function computeVat(VatInput $input): VatBreakdown;

    /**
     * The mandatory fields of the electronic service sheet that are still missing, by field name.
     *
     * @param  array<string, mixed>  $sheet
     * @return list<string>
     */
    public function serviceSheetMissingFields(array $sheet): array;
}
