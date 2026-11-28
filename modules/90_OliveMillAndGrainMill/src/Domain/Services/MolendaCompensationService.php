<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Services;

use Modules\OliveMillAndGrainMill\Domain\Enums\SettlementMode;
use Modules\OliveMillAndGrainMill\Domain\Enums\TariffMode;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\MolendaCalculation;

/**
 * Exact fiscal and quantity maths of the in-kind payment (molenda): the milling service fee, the oil kept by the mill in payment, the VAT of both
 * documents and the cash balance left once the self-invoice for the oil offsets the service invoice. Money is computed in cents.
 */
final class MolendaCompensationService
{
    /**
     * @param  float  $oliveNetKg  net weight of the crop milled
     * @param  float  $outputKg  oil produced by the cycle
     * @param  float  $availableKg  oil that belongs to the farmer (picked up or held by the mill on his behalf): the retained oil cannot exceed it
     * @param  float  $tariffValue  euro per quintal of olives, euro per kg of oil, a flat fee in euro, or the molenda percentage of the oil
     * @param  float|null  $molendaPercent  share of the oil kept by the mill (hybrid mode)
     * @param  float|null  $fixedRetainedKg  fixed quantity of oil kept by the mill (hybrid mode, wins over the percentage)
     *
     * @throws OliveMillException
     */
    public function calculate(
        float $oliveNetKg,
        float $outputKg,
        float $availableKg,
        TariffMode $tariff,
        float $tariffValue,
        float $serviceVatRate,
        SettlementMode $mode,
        ?float $molendaPercent,
        ?float $fixedRetainedKg,
        float $oilPricePerKg,
        float $purchaseVatRate,
    ): MolendaCalculation {
        if ($tariffValue < 0.0 || $serviceVatRate < 0.0 || $purchaseVatRate < 0.0 || $oilPricePerKg < 0.0) {
            throw OliveMillException::because('negative_value');
        }

        if ($tariff === TariffMode::PurePercentageMolenda) {
            if ($mode === SettlementMode::CashOrBank) {
                throw OliveMillException::because('percentage_tariff_requires_in_kind');
            }

            if ($tariffValue > 100.0) {
                throw OliveMillException::because('percentage_out_of_range');
            }
        }

        $retainedKg = 0.0;
        $serviceNetCents = null;

        if ($tariff === TariffMode::PurePercentageMolenda) {
            $retainedKg = round($outputKg * $tariffValue / 100, 3);
        } else {
            $serviceNetCents = $this->cents(match ($tariff) {
                TariffMode::PerQuintalOlives => $oliveNetKg / 100 * $tariffValue,
                TariffMode::PerKgOil => $outputKg * $tariffValue,
                default => $tariffValue,
            });
        }

        if ($tariff !== TariffMode::PurePercentageMolenda) {
            $retainedKg = match ($mode) {
                SettlementMode::CashOrBank => 0.0,
                SettlementMode::FullInKindMolenda => $this->fullInKindKg($serviceNetCents, $serviceVatRate, $oilPricePerKg, $purchaseVatRate),
                SettlementMode::HybridMolenda => $this->hybridKg($outputKg, $molendaPercent, $fixedRetainedKg),
            };
        }

        if ($retainedKg > 0.0 && $oilPricePerKg <= 0.0) {
            throw OliveMillException::because('oil_price_required');
        }

        if ($retainedKg > $availableKg + 0.0005) {
            throw OliveMillException::because('retained_exceeds_available', ['retained' => $retainedKg, 'available' => $availableKg]);
        }

        $retainedNetCents = $this->cents($retainedKg * $oilPricePerKg);
        $serviceNetCents ??= $retainedNetCents;
        $serviceVatCents = $this->percentOf($serviceNetCents, $serviceVatRate);
        $serviceTotalCents = $serviceNetCents + $serviceVatCents;
        $purchaseVatCents = $this->percentOf($retainedNetCents, $purchaseVatRate);
        $purchaseTotalCents = $retainedNetCents + $purchaseVatCents;
        $balanceCents = $serviceTotalCents - $purchaseTotalCents;

        return new MolendaCalculation(
            serviceNet: $this->money($serviceNetCents),
            serviceVatRate: number_format($serviceVatRate, 2, '.', ''),
            serviceVat: $this->money($serviceVatCents),
            serviceTotal: $this->money($serviceTotalCents),
            retainedKg: number_format($retainedKg, 3, '.', ''),
            retainedPercent: $outputKg > 0.0 ? number_format($retainedKg / $outputKg * 100, 2, '.', '') : '0.00',
            oilPricePerKg: number_format($oilPricePerKg, 4, '.', ''),
            retainedValueNet: $this->money($retainedNetCents),
            purchaseVatRate: number_format($purchaseVatRate, 2, '.', ''),
            purchaseVat: $this->money($purchaseVatCents),
            purchaseTotal: $this->money($purchaseTotalCents),
            cashBalance: $this->money($balanceCents),
            compensation: $this->money(min($serviceTotalCents, $purchaseTotalCents)),
            cashDirection: $balanceCents > 0 ? 'farmer_pays' : ($balanceCents < 0 ? 'mill_pays' : 'none'),
            farmerPickupKg: number_format(max(0.0, $availableKg - $retainedKg), 3, '.', ''),
        );
    }

    /**
     * Oil whose purchase total (net plus VAT) covers the whole service total.
     *
     * @throws OliveMillException oil_price_required
     */
    private function fullInKindKg(int $serviceNetCents, float $serviceVatRate, float $oilPricePerKg, float $purchaseVatRate): float
    {
        if ($oilPricePerKg <= 0.0) {
            throw OliveMillException::because('oil_price_required');
        }

        $serviceTotal = ($serviceNetCents + $this->percentOf($serviceNetCents, $serviceVatRate)) / 100;

        return round($serviceTotal / ($oilPricePerKg * (1 + $purchaseVatRate / 100)), 3);
    }

    /**
     * @throws OliveMillException molenda_share_required, percentage_out_of_range
     */
    private function hybridKg(float $outputKg, ?float $percent, ?float $fixedKg): float
    {
        if ($fixedKg !== null && $fixedKg > 0.0) {
            return round($fixedKg, 3);
        }

        if ($percent === null || $percent <= 0.0) {
            throw OliveMillException::because('molenda_share_required');
        }

        if ($percent > 100.0) {
            throw OliveMillException::because('percentage_out_of_range');
        }

        return round($outputKg * $percent / 100, 3);
    }

    /**
     * A percentage of an amount in cents, rounded half up to the cent.
     */
    private function percentOf(int $cents, float $rate): int
    {
        return (int) round($cents * $rate / 100, 0, PHP_ROUND_HALF_UP);
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
