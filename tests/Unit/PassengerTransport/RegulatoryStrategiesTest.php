<?php

declare(strict_types=1);

use Modules\PassengerTransport\Domain\Enums\DrivingRegime;
use Modules\PassengerTransport\Domain\Enums\VatLineKind;
use Modules\PassengerTransport\Domain\Enums\VehicleCategory;
use Modules\PassengerTransport\Domain\Strategies\ChOlr1Olr2BusMwStStrategy;
use Modules\PassengerTransport\Domain\Strategies\ItNccFoglioServizioArt7QuaterIvaStrategy;
use Modules\PassengerTransport\Domain\ValueObjects\DriverCounters;
use Modules\PassengerTransport\Domain\ValueObjects\TripPlan;
use Modules\PassengerTransport\Domain\ValueObjects\VatInput;

function ptrVat(string $net, string $total, string $it, string $ch, string $other = '0', ?VehicleCategory $category = null, bool $urban = false, bool $art23 = false, string $currency = 'EUR'): VatInput
{
    return new VatInput($net, $currency, $total, $it, $ch, $other, $category, $urban, $art23);
}

it('splits a Malpensa to Lugano fare by kilometres: 10 % Italian VAT on the Italian share, the Swiss leg outside the territory (N2.1)', function (): void {
    $breakdown = (new ItNccFoglioServizioArt7QuaterIvaStrategy)->computeVat(ptrVat('200.00', '60', '40', '20'));
    [$italy, $abroad] = $breakdown->lines;

    expect($italy->kind)->toBe(VatLineKind::ItDomestic)
        ->and($italy->net)->toBe('133.33')
        ->and($italy->rate)->toBe('10.00')
        ->and($italy->tax)->toBe('13.33')
        ->and($italy->nature)->toBeNull()
        ->and($abroad->kind)->toBe(VatLineKind::ItForeignLeg)
        ->and($abroad->net)->toBe('66.67')
        ->and($abroad->rate)->toBe('0.00')
        ->and($abroad->nature)->toBe('N2.1')
        ->and($abroad->legalReference)->toContain('Art. 7-quater')
        ->and($breakdown->totalNet())->toBe('200.00')
        ->and($breakdown->totalTax())->toBe('13.33')
        ->and($breakdown->totalGross())->toBe('213.33')
        ->and($breakdown->domesticShare)->toBe('0.6667')
        ->and($breakdown->foreignShare)->toBe('0.3333');
});

it('always lets the lines add up to the agreed fare whatever the rounding', function (): void {
    foreach (['99.99', '100.01', '333.33', '1.00'] as $net) {
        $breakdown = (new ItNccFoglioServizioArt7QuaterIvaStrategy)->computeVat(ptrVat($net, '70', '23', '47'));

        expect($breakdown->totalNet())->toBe($net);
    }
});

it('uses the configured natura code for the foreign leg', function (): void {
    $breakdown = (new ItNccFoglioServizioArt7QuaterIvaStrategy('10', 'N3.4'))->computeVat(ptrVat('100.00', '50', '25', '25'));

    expect($breakdown->lines[1]->nature)->toBe('N3.4');
});

it('applies 10 % to a domestic Italian transfer and bills a trip wholly abroad outside the Italian territory', function (): void {
    $strategy = new ItNccFoglioServizioArt7QuaterIvaStrategy;
    $domestic = $strategy->computeVat(ptrVat('100.00', '40', '40', '0'));
    $abroad = $strategy->computeVat(ptrVat('100.00', '40', '0', '40'));

    expect($domestic->lines)->toHaveCount(1)
        ->and($domestic->lines[0]->tax)->toBe('10.00')
        ->and($domestic->totalGross())->toBe('110.00')
        ->and($abroad->lines)->toHaveCount(1)
        ->and($abroad->lines[0]->kind)->toBe(VatLineKind::ItForeignLeg)
        ->and($abroad->totalTax())->toBe('0.00');
});

it('exempts taxi and municipal urban service within the 50 km perimeter under Art. 10 n. 14 with natura N4', function (): void {
    $strategy = new ItNccFoglioServizioArt7QuaterIvaStrategy;
    $taxi = $strategy->computeVat(ptrVat('30.00', '8', '8', '0', category: VehicleCategory::Taxi));
    $urban = $strategy->computeVat(ptrVat('30.00', '45', '45', '0', urban: true));
    $tooFar = $strategy->computeVat(ptrVat('30.00', '60', '60', '0', urban: true));

    expect($taxi->lines[0]->kind)->toBe(VatLineKind::ItExemptArt10N14)
        ->and($taxi->lines[0]->nature)->toBe('N4')
        ->and($taxi->totalTax())->toBe('0.00')
        ->and($urban->lines[0]->kind)->toBe(VatLineKind::ItExemptArt10N14)
        ->and($tooFar->lines[0]->kind)->toBe(VatLineKind::ItDomestic);
});

it('charges the Swiss standard rate on the Swiss kilometres only and leaves the foreign leg outside the Swiss scope', function (): void {
    $breakdown = (new ChOlr1Olr2BusMwStStrategy)->computeVat(ptrVat('300.00', '90', '30', '60', currency: 'CHF'));
    [$swiss, $foreign] = $breakdown->lines;

    expect($swiss->kind)->toBe(VatLineKind::ChDomestic)
        ->and($swiss->net)->toBe('200.00')
        ->and($swiss->rate)->toBe('8.1')
        ->and($swiss->tax)->toBe('16.20')
        ->and($foreign->kind)->toBe(VatLineKind::ChForeignLeg)
        ->and($foreign->net)->toBe('100.00')
        ->and($foreign->tax)->toBe('0.00')
        ->and($breakdown->totalGross())->toBe('316.20');
});

it('exempts the whole fare under Art. 23 LIVA when the international transport rules apply', function (): void {
    $breakdown = (new ChOlr1Olr2BusMwStStrategy)->computeVat(ptrVat('300.00', '90', '30', '60', art23: true, currency: 'CHF'));

    expect($breakdown->lines)->toHaveCount(1)
        ->and($breakdown->lines[0]->kind)->toBe(VatLineKind::ChExemptArt23)
        ->and($breakdown->lines[0]->legalReference)->toContain('Info IVA 11')
        ->and($breakdown->totalTax())->toBe('0.00');
});

it('charges the Swiss rate on a domestic Swiss transfer and on a fare without kilometres', function (): void {
    $strategy = new ChOlr1Olr2BusMwStStrategy('8.1');

    expect($strategy->computeVat(ptrVat('100.00', '30', '0', '30', currency: 'CHF'))->totalTax())->toBe('8.10')
        ->and($strategy->computeVat(ptrVat('100.00', '0', '0', '0', currency: 'CHF'))->totalTax())->toBe('8.10');
});

it('puts a vehicle under OLR 1 above 3.5 t or more than nine seats including the driver', function (): void {
    foreach ([new ChOlr1Olr2BusMwStStrategy, new ItNccFoglioServizioArt7QuaterIvaStrategy] as $strategy) {
        expect($strategy->drivingRegime(3.5, 8))->toBe(DrivingRegime::Olr2)
            ->and($strategy->drivingRegime(3.6, 4))->toBe(DrivingRegime::Olr1Ec561)
            ->and($strategy->drivingRegime(3.0, 9))->toBe(DrivingRegime::Olr1Ec561)
            ->and($strategy->drivingRegime(2.2, 3))->toBe(DrivingRegime::Olr2);
    }
});

it('demotes the OLR 2 limits to advice in Italy until the trip enters Switzerland', function (): void {
    $strategy = new ItNccFoglioServizioArt7QuaterIvaStrategy;
    $trip = new TripPlan(new DateTimeImmutable('2028-03-10 08:00:00'), new DateTimeImmutable('2028-03-10 12:00:00'), 240);

    $domestic = $strategy->evaluateDriving(DrivingRegime::Olr2, new DriverCounters, $trip, false);
    $crossing = $strategy->evaluateDriving(DrivingRegime::Olr2, new DriverCounters, $trip, true);
    $coach = $strategy->evaluateDriving(DrivingRegime::Olr1Ec561, new DriverCounters, new TripPlan($trip->startsAt, $trip->endsAt, 300), false);

    expect($domestic)->not->toBe([])
        ->and(array_filter($domestic, static fn ($issue): bool => $issue->isBlocking()))->toBe([])
        ->and(array_filter($crossing, static fn ($issue): bool => $issue->isBlocking()))->not->toBe([])
        ->and(array_filter($coach, static fn ($issue): bool => $issue->isBlocking()))->not->toBe([]);
});

it('lists the mandatory fields of the Italian service sheet that are still empty', function (): void {
    $strategy = new ItNccFoglioServizioArt7QuaterIvaStrategy;
    $complete = array_fill_keys(ItNccFoglioServizioArt7QuaterIvaStrategy::MANDATORY_FIELDS, 'x');

    expect($strategy->serviceSheetMissingFields($complete))->toBe([])
        ->and($strategy->serviceSheetMissingFields([...$complete, 'garage_departure_at' => null, 'customer' => '']))->toBe(['garage_departure_at', 'customer'])
        ->and($strategy->serviceSheetMissingFields([]))->toBe(ItNccFoglioServizioArt7QuaterIvaStrategy::MANDATORY_FIELDS)
        ->and((new ChOlr1Olr2BusMwStStrategy)->serviceSheetMissingFields([]))->toBe([]);
});
