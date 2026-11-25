<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Strategies;

use Modules\PassengerTransport\Domain\Enums\DrivingRegime;
use Modules\PassengerTransport\Domain\Enums\Jurisdiction;
use Modules\PassengerTransport\Domain\Enums\VatLineKind;
use Modules\PassengerTransport\Domain\Enums\VehicleCategory;
use Modules\PassengerTransport\Domain\Support\Dec;
use Modules\PassengerTransport\Domain\Support\DrivingTimeEvaluator;
use Modules\PassengerTransport\Domain\ValueObjects\DriverCounters;
use Modules\PassengerTransport\Domain\ValueObjects\TripPlan;
use Modules\PassengerTransport\Domain\ValueObjects\VatBreakdown;
use Modules\PassengerTransport\Domain\ValueObjects\VatInput;
use Modules\PassengerTransport\Domain\ValueObjects\VatLine;

/**
 * Italy: the electronic NCC service sheet (Art. 11 Law 21/1992 and MIT decrees) and the VAT of passenger transport (DPR 633/72).
 * 10 % for domestic transport (Tabella A, Parte III, n. 127-novies); exempt under Art. 10 n. 14 for taxi and municipal urban service
 * within the urban perimeter; a cross-border fare is split in proportion to the kilometres driven in Italy (taxable at 10 %) and
 * abroad (outside the Italian territory, Art. 7-quater co. 1 lett. b and Art. 9 co. 1 n. 1), each with its natura code.
 */
final class ItNccFoglioServizioArt7QuaterIvaStrategy extends AbstractPassengerTransportStrategy
{
    /** Fields of the service sheet that must be filled before the vehicle leaves the garage. */
    public const array MANDATORY_FIELDS = ['progressive_sheet_number', 'vehicle_plate', 'driver_employee_id', 'garage_departure_at', 'garage_municipality', 'customer', 'pickup_address', 'dropoff_address'];

    public function __construct(
        private readonly string $rate = '10',
        private readonly string $foreignNature = 'N2.1',
        private readonly string $urbanPerimeterKm = '50',
        DrivingTimeEvaluator $driving = new DrivingTimeEvaluator,
    ) {
        parent::__construct($driving);
    }

    public function jurisdiction(): Jurisdiction
    {
        return Jurisdiction::It;
    }

    /**
     * Light vehicles are outside Reg. (EC) 561/2006 in Italy: the OLR 2 limits then only advise, and bind once the trip enters Switzerland.
     */
    public function evaluateDriving(DrivingRegime $regime, DriverCounters $counters, TripPlan $trip, bool $tripTouchesCh): array
    {
        $issues = parent::evaluateDriving($regime, $counters, $trip, $tripTouchesCh);

        return $regime === DrivingRegime::Olr2 && ! $tripTouchesCh
            ? array_map(static fn ($issue) => $issue->demoted(), $issues)
            : $issues;
    }

    public function computeVat(VatInput $input): VatBreakdown
    {
        $total = Dec::cmp($input->totalKm, '0') > 0 ? $input->totalKm : $input->itKm;
        $foreignKm = Dec::add($input->chKm, $input->otherEuKm);
        $domesticShare = Dec::cmp($total, '0') <= 0 ? '1.0000' : $this->share($input->itKm, $total);
        $foreignShare = bcsub('1', $domesticShare, 4);
        $net = Dec::round($input->netAmount);
        $domesticOnly = Dec::cmp($foreignKm, '0') <= 0;

        $urbanExempt = $input->vehicleCategory === VehicleCategory::Taxi
            || ($input->municipalUrbanService && Dec::cmp($total, $this->urbanPerimeterKm) <= 0);

        if ($domesticOnly && $urbanExempt) {
            return new VatBreakdown($input->currency, [new VatLine(VatLineKind::ItExemptArt10N14, Dec::round($total), $net, '0.00', '0.00', 'N4', 'Art. 10 n. 14 DPR 633/72')], '1.0000', '0.0000');
        }

        if (Dec::cmp($total, '0') <= 0 || $domesticOnly) {
            return new VatBreakdown($input->currency, [new VatLine(VatLineKind::ItDomestic, Dec::round($total), $net, $this->rate(), $this->tax($net, $this->rate), null, 'Tabella A, Parte III, n. 127-novies DPR 633/72')], '1.0000', '0.0000');
        }

        [$domestic, $foreign] = $this->split($net, $input->itKm, $total);
        $lines = [];

        if (Dec::cmp($domestic, '0') > 0) {
            $lines[] = new VatLine(VatLineKind::ItDomestic, Dec::round($input->itKm), $domestic, $this->rate(), $this->tax($domestic, $this->rate), null, 'Tabella A, Parte III, n. 127-novies DPR 633/72');
        }

        $lines[] = new VatLine(VatLineKind::ItForeignLeg, Dec::round($foreignKm), $foreign, '0.00', '0.00', $this->foreignNature, 'Art. 7-quater co. 1 lett. b) e Art. 9 co. 1 n. 1 DPR 633/72');

        return new VatBreakdown($input->currency, $lines, $domesticShare, $foreignShare);
    }

    public function serviceSheetMissingFields(array $sheet): array
    {
        return array_values(array_filter(self::MANDATORY_FIELDS, static fn (string $field): bool => ! isset($sheet[$field]) || $sheet[$field] === '' || $sheet[$field] === []));
    }

    private function rate(): string
    {
        return Dec::round($this->rate);
    }
}
