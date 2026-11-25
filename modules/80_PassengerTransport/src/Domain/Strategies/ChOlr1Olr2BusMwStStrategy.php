<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Strategies;

use Modules\PassengerTransport\Domain\Enums\Jurisdiction;
use Modules\PassengerTransport\Domain\Enums\VatLineKind;
use Modules\PassengerTransport\Domain\Support\Dec;
use Modules\PassengerTransport\Domain\Support\DrivingTimeEvaluator;
use Modules\PassengerTransport\Domain\ValueObjects\VatBreakdown;
use Modules\PassengerTransport\Domain\ValueObjects\VatInput;
use Modules\PassengerTransport\Domain\ValueObjects\VatLine;

/**
 * Switzerland: OLR 1 / ARV 1 (aligned with Reg. (EC) 561/2006) for vehicles over 3.5 t or more than nine seats, OLR 2 / ARV 2 for
 * professional drivers of light passenger vehicles (taxi, NCC), and MWST (LIVA): the standard rate applies to the kilometres driven
 * in Switzerland of a cross-border journey, the foreign kilometres are outside the Swiss scope, and the exemption of Art. 23 LIVA
 * (Info IVA 11) covers the whole fare when the international transport rules apply.
 */
final class ChOlr1Olr2BusMwStStrategy extends AbstractPassengerTransportStrategy
{
    public function __construct(private readonly string $standardRate = '8.1', DrivingTimeEvaluator $driving = new DrivingTimeEvaluator)
    {
        parent::__construct($driving);
    }

    public function jurisdiction(): Jurisdiction
    {
        return Jurisdiction::Ch;
    }

    public function computeVat(VatInput $input): VatBreakdown
    {
        $total = Dec::cmp($input->totalKm, '0') > 0 ? $input->totalKm : $input->chKm;
        $domesticShare = Dec::cmp($total, '0') <= 0 ? '1.0000' : $this->share($input->chKm, $total);

        if ($input->art23Exemption) {
            return new VatBreakdown($input->currency, [new VatLine(VatLineKind::ChExemptArt23, $total, Dec::round($input->netAmount), '0.00', '0.00', null, 'Art. 23 LIVA (MWSTG) / Info IVA 11')], $domesticShare, bcsub('1', $domesticShare, 4));
        }

        if (Dec::cmp($total, '0') <= 0) {
            $net = Dec::round($input->netAmount);

            return new VatBreakdown($input->currency, [new VatLine(VatLineKind::ChDomestic, '0.00', $net, $this->standardRate, $this->tax($net, $this->standardRate), null, 'Art. 25 LIVA (MWSTG)')], '1.0000', '0.0000');
        }

        [$domestic, $foreign] = $this->split($input->netAmount, $input->chKm, $total);
        $lines = [];

        if (Dec::cmp($domestic, '0') > 0 || Dec::cmp($foreign, '0') <= 0) {
            $lines[] = new VatLine(VatLineKind::ChDomestic, Dec::round($input->chKm), $domestic, $this->standardRate, $this->tax($domestic, $this->standardRate), null, 'Art. 25 LIVA (MWSTG)');
        }

        if (Dec::cmp($foreign, '0') > 0) {
            $lines[] = new VatLine(VatLineKind::ChForeignLeg, Dec::round(Dec::add($input->itKm, $input->otherEuKm)), $foreign, '0.00', '0.00', null, 'Art. 8 LIVA (MWSTG) / Info IVA 11: kilometres driven abroad');
        }

        return new VatBreakdown($input->currency, $lines, $domesticShare, bcsub('1', $domesticShare, 4));
    }

    public function serviceSheetMissingFields(array $sheet): array
    {
        return [];
    }
}
