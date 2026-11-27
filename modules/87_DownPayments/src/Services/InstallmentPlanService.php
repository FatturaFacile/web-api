<?php

declare(strict_types=1);

namespace Modules\DownPayments\Services;

use Modules\DownPayments\Domain\Enums\CalculationMode;
use Modules\DownPayments\Domain\Enums\InstallmentTrigger;
use Modules\DownPayments\Domain\Enums\InstrumentType;
use Modules\DownPayments\Exceptions\DownPaymentException;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The installment plan wizard: the preset templates and the sequential computation of every installment over the VAT rates of
 * the source. Each installment is split over what the earlier installments left of every rate (so the last one takes exactly the
 * rest, to the cent) and the plan can never state more than the source holds.
 *
 * @phpstan-type PlanRow array{sequence: int, milestone_label: string, trigger_type: string, due_date: string|null, calculation_mode: string, percentage: string|null, amount_net: string, amount_vat: string, amount_gross: string, instrument_type: string, vat_breakdown: list<array{vat_rate: string, net: string, vat: string, gross: string}>, rounding_drift: string}
 */
final class InstallmentPlanService
{
    public const string TEMPLATE_30_40_30 = '30_40_30';

    public const string TEMPLATE_50_50 = '50_50';

    public const string TEMPLATE_CAPARRA_BALANCE = 'caparra_balance';

    public function __construct(private readonly MultiVatProportionalAllocator $allocator) {}

    /**
     * The preset templates of the wizard, as installment inputs.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function presets(): array
    {
        $advance = InstrumentType::AdvanceInvoiceImmediate->value;
        $percentage = CalculationMode::Percentage->value;

        return [
            self::TEMPLATE_30_40_30 => [
                ['trigger_type' => InstallmentTrigger::OnOrderConfirmation->value, 'calculation_mode' => $percentage, 'percentage' => '30', 'instrument_type' => $advance],
                ['trigger_type' => InstallmentTrigger::OnMaterialDelivery->value, 'calculation_mode' => $percentage, 'percentage' => '40', 'instrument_type' => $advance],
                ['trigger_type' => InstallmentTrigger::OnCommissioning->value, 'calculation_mode' => $percentage, 'percentage' => '30', 'instrument_type' => $advance],
            ],
            self::TEMPLATE_50_50 => [
                ['trigger_type' => InstallmentTrigger::OnOrderConfirmation->value, 'calculation_mode' => $percentage, 'percentage' => '50', 'instrument_type' => $advance],
                ['trigger_type' => InstallmentTrigger::OnMaterialDelivery->value, 'calculation_mode' => $percentage, 'percentage' => '50', 'instrument_type' => $advance],
            ],
            self::TEMPLATE_CAPARRA_BALANCE => [
                ['trigger_type' => InstallmentTrigger::OnOrderConfirmation->value, 'calculation_mode' => $percentage, 'percentage' => '10', 'instrument_type' => InstrumentType::EarnestMoneyDeposit->value],
                ['trigger_type' => InstallmentTrigger::OnMaterialDelivery->value, 'calculation_mode' => $percentage, 'percentage' => '40', 'instrument_type' => $advance],
            ],
        ];
    }

    /**
     * Computes the plan over the VAT rates of the source, installment by installment.
     *
     * @param  array<string, string>  $bases  net of the source per VAT rate
     * @param  list<array<string, mixed>>  $installments  trigger_type, calculation_mode, percentage or amount, instrument_type, milestone_label, due_date
     * @return list<PlanRow>
     *
     * @throws DownPaymentException when an installment is malformed or the plan states more than the source holds
     */
    public function compute(array $bases, array $installments): array
    {
        if ($installments === []) {
            throw new DownPaymentException('plan_requires_installments');
        }

        $residual = $bases;
        $contractNet = array_reduce($bases, static fn (string $sum, string $net): string => Decimal::add($sum, $net), '0');
        $contractGross = $this->grossOf($bases);
        $reservedDeposits = '0';
        $rows = [];

        foreach ($installments as $index => $input) {
            $sequence = $index + 1;
            $instrument = InstrumentType::tryFrom((string) ($input['instrument_type'] ?? '')) ?? throw new DownPaymentException('installment_invalid_instrument', 422, ['sequence' => $sequence]);
            $mode = CalculationMode::tryFrom((string) ($input['calculation_mode'] ?? '')) ?? throw new DownPaymentException('installment_invalid_mode', 422, ['sequence' => $sequence]);
            $trigger = InstallmentTrigger::tryFrom((string) ($input['trigger_type'] ?? '')) ?? throw new DownPaymentException('installment_invalid_trigger', 422, ['sequence' => $sequence]);
            $percentage = $mode === CalculationMode::Percentage ? Decimal::round2((string) ($input['percentage'] ?? '0')) : null;
            $amount = $mode === CalculationMode::Percentage ? null : Decimal::round2((string) ($input['amount'] ?? '0'));

            if (($percentage !== null && ! Decimal::isPositive($percentage)) || ($amount !== null && ! Decimal::isPositive($amount)) || ($percentage !== null && Decimal::compare($percentage, '100') > 0)) {
                throw new DownPaymentException('installment_invalid_amount', 422, ['sequence' => $sequence]);
            }

            $row = $this->isDeposit($instrument)
                ? $this->depositRow($mode, $percentage, $amount, $contractGross)
                : $this->advanceRow($mode, $percentage, $amount, $contractNet, $residual, $sequence);

            if ($instrument === InstrumentType::SecurityDeposit) {
                // A security deposit is refunded, never part of the price
            } elseif ($instrument === InstrumentType::EarnestMoneyDeposit) {
                $reservedDeposits = Decimal::add($reservedDeposits, $row['amount_gross']);
            } else {
                foreach ($row['vat_breakdown'] as $slice) {
                    $residual[$slice['vat_rate']] = Decimal::round2(Decimal::sub($residual[$slice['vat_rate']], $slice['net']));
                }
            }

            $rows[] = [
                'sequence' => $sequence,
                'milestone_label' => (string) ($input['milestone_label'] ?? '') !== '' ? mb_substr((string) $input['milestone_label'], 0, 200) : (string) __('down_payments.plan.default_label', ['number' => $sequence]),
                'trigger_type' => $trigger->value,
                'due_date' => isset($input['due_date']) && $input['due_date'] !== '' ? (string) $input['due_date'] : null,
                'calculation_mode' => $mode->value,
                'percentage' => $percentage,
                'instrument_type' => $instrument->value,
                ...$row,
            ];
        }

        $advancesGross = array_reduce($rows, fn (string $sum, array $row): string => $row['instrument_type'] === InstrumentType::SecurityDeposit->value ? $sum : Decimal::add($sum, $row['amount_gross']), '0');

        if (Decimal::compare(Decimal::round2($advancesGross), $contractGross) > 0) {
            throw new DownPaymentException('plan_exceeds_contract', 422, ['contract_gross' => $contractGross]);
        }

        return $rows;
    }

    /**
     * What one advance installment asks for, split over the VAT rates still open: the amount and the slices by rate.
     *
     * @param  array<string, string>  $openBases  net still open per VAT rate
     * @return array{amount_net: string, amount_vat: string, amount_gross: string, vat_breakdown: list<array{vat_rate: string, net: string, vat: string, gross: string}>, rounding_drift: string}
     *
     * @throws DownPaymentException when the amount exceeds what is open
     */
    public function advanceRow(CalculationMode $mode, ?string $percentage, ?string $amount, string $contractNet, array $openBases, int $sequence = 0): array
    {
        $openNet = array_reduce($openBases, static fn (string $sum, string $net): string => Decimal::add($sum, $net), '0');
        $requestedNet = match ($mode) {
            CalculationMode::Percentage => Decimal::round2(Decimal::div(Decimal::mul($contractNet, (string) $percentage), '100')),
            CalculationMode::FixedNet => (string) $amount,
            CalculationMode::FixedGross => null,
        };

        if (Decimal::isZero($openNet) || ($requestedNet !== null && Decimal::compare($requestedNet, $openNet) > 0)) {
            throw new DownPaymentException('advance_exceeds_residual', 422, ['sequence' => $sequence, 'residual_net' => Decimal::round2($openNet)]);
        }

        if ($requestedNet !== null) {
            // The last slice of a percentage plan asks exactly for what is left, never a cent more
            $slices = $this->allocator->allocateNet(Decimal::compare(Decimal::sub($openNet, $requestedNet), '0.01') < 0 ? $openNet : $requestedNet, $openBases);
            $drift = '0.00';
        } else {
            if (Decimal::compare((string) $amount, $this->grossOf($openBases)) > 0) {
                throw new DownPaymentException('advance_exceeds_residual', 422, ['sequence' => $sequence, 'residual_net' => Decimal::round2($openNet)]);
            }

            $slices = $this->allocator->allocateGross((string) $amount, $openBases);
            $drift = $this->allocator->drift((string) $amount, $slices);
        }

        foreach ($slices as $slice) {
            if (Decimal::compare($slice['net'], $openBases[$slice['vat_rate']] ?? '0') > 0) {
                throw new DownPaymentException('advance_exceeds_residual', 422, ['sequence' => $sequence, 'residual_net' => Decimal::round2($openNet)]);
            }
        }

        return [
            'amount_net' => $this->allocator->sum($slices, 'net'),
            'amount_vat' => $this->allocator->sum($slices, 'vat'),
            'amount_gross' => $this->allocator->sum($slices, 'gross'),
            'vat_breakdown' => $slices,
            'rounding_drift' => $drift,
        ];
    }

    /**
     * @return array{amount_net: string, amount_vat: string, amount_gross: string, vat_breakdown: list<array{vat_rate: string, net: string, vat: string, gross: string}>, rounding_drift: string}
     */
    private function depositRow(CalculationMode $mode, ?string $percentage, ?string $amount, string $contractGross): array
    {
        $gross = $mode === CalculationMode::Percentage
            ? Decimal::round2(Decimal::div(Decimal::mul($contractGross, (string) $percentage), '100'))
            : (string) $amount;

        // A deposit is outside the VAT scope: net and gross are the same amount and no VAT is stated
        return ['amount_net' => $gross, 'amount_vat' => '0.00', 'amount_gross' => $gross, 'vat_breakdown' => [], 'rounding_drift' => '0.00'];
    }

    private function isDeposit(InstrumentType $instrument): bool
    {
        return in_array($instrument, [InstrumentType::EarnestMoneyDeposit, InstrumentType::SecurityDeposit], true);
    }

    /**
     * @param  array<string, string>  $bases
     */
    public function grossOf(array $bases): string
    {
        $gross = '0';

        foreach ($bases as $rate => $net) {
            $gross = Decimal::add($gross, Decimal::add($net, Decimal::round2(Decimal::div(Decimal::mul($net, (string) $rate), '100'))));
        }

        return Decimal::round2($gross);
    }
}
