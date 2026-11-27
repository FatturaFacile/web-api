<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GreenCare\Application\Commands\GenerateGreenCareBillingCommand;
use Modules\GreenCare\Domain\Compliance\ComplianceStrategyResolver;
use Modules\GreenCare\Domain\Enums\BillingMode;
use Modules\GreenCare\Domain\Enums\BillingStatus;
use Modules\GreenCare\Domain\Enums\InstallmentFrequency;
use Modules\GreenCare\Domain\Exceptions\GreenCareException;
use Modules\GreenCare\Models\CrewRouteStop;
use Modules\GreenCare\Models\GreenWasteSlip;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;

/**
 * Computes what a seasonal contract can bill now: the next fixed installment and the winter standby fee, the extra passages beyond the
 * included ones, the time and material hours with the plants, and the green waste that is billable to the customer; then the VAT and, for an
 * Italian condominium, the 4% withholding (ritenuta d'acconto, Art. 25-ter DPR 600/73) with its base and the net payable.
 *
 * @phpstan-type Line array{kind: string, description: string, quantity: string, unit_price: string, tax_rate: string, net: string, product_id?: string}
 */
final class GreenCareBillingBuilder
{
    public function __construct(private readonly ComplianceStrategyResolver $strategies) {}

    /**
     * @return array{
     *     lines: list<Line>,
     *     total_net: string,
     *     total_vat: string,
     *     withholding: array{applies: bool, percent: float, base: string, amount: string, type: string, reason: string, legal_basis: string}|null,
     *     net_payable: string,
     *     jurisdiction: string,
     *     condominium: array{fiscal_code: string|null, administrator_name: string|null}|null,
     *     stops: Collection<int, CrewRouteStop>,
     *     slips: Collection<int, GreenWasteSlip>,
     *     installment_number: int|null,
     *     snow_standby: bool
     * }
     *
     * @throws GreenCareException contract_not_billable, condominium_data_missing, nothing_to_bill
     */
    public function build(SeasonalMaintenanceContract $contract, GenerateGreenCareBillingCommand $command): array
    {
        if ($contract->status->value === 'draft') {
            throw GreenCareException::because('contract_not_billable');
        }

        $strategy = $this->strategies->for($contract->jurisdiction);
        $withholdingRule = $contract->jurisdiction === 'IT' && $contract->apply_condominium_withholding ? $strategy->condominiumWithholding() : null;

        if (($withholdingRule !== null || $contract->is_condominium) && trim((string) $contract->condominium_fiscal_code) === '') {
            throw GreenCareException::because('condominium_data_missing');
        }

        $rate = Decimal::normalize((string) $contract->vat_rate);
        $lines = [];

        $installmentNumber = null;

        if ($command->includeInstallment && $contract->billing_mode === BillingMode::FixedInstallments) {
            $installment = $this->nextInstallment($contract);

            if ($installment !== null) {
                $installmentNumber = $installment['number'];
                $lines[] = $this->line('installment', (string) __('green_care.billing.installment', ['number' => $installment['number'], 'total' => $installment['total'], 'contract' => $contract->contract_number]), '1', $installment['amount'], $rate);
            }
        }

        $snow = $command->includeSnowStandby && ! $contract->snow_standby_billed && $contract->snow_removal_standby_fee !== null && Decimal::isPositive((string) $contract->snow_removal_standby_fee);

        if ($snow) {
            $lines[] = $this->line('snow_standby', (string) __('green_care.billing.snow_standby', ['contract' => $contract->contract_number]), '1', (string) $contract->snow_removal_standby_fee, $rate);
        }

        $stops = $this->billableStops($contract, $command);
        $hourlyRate = $this->hourlyRate($contract);

        $billedStops = [];

        foreach ($stops as $stop) {
            $linesBefore = count($lines);
            $date = $stop->actual_checkout_at?->format('d/m/Y') ?? '-';

            if ($stop->is_extra_passage && $command->includeExtraPassages) {
                $lines[] = $this->line('extra_passage', (string) __('green_care.billing.extra_passage', ['number' => $stop->passage_number, 'included' => $contract->included_passages_count, 'date' => $date]), '1', (string) $stop->extra_passage_amount, $rate);
            } elseif (! $stop->is_extra_passage && $command->includeTimeAndMaterial && $contract->billing_mode === BillingMode::TimeAndMaterial) {
                $hours = Decimal::normalize((string) $stop->loggedHours());

                if (Decimal::isPositive($hours) && Decimal::isPositive($hourlyRate)) {
                    $lines[] = $this->line('time_and_material', (string) __('green_care.billing.time_and_material', ['date' => $date, 'site' => $contract->site->name ?? '']), $hours, $hourlyRate, $rate);
                }
            }

            foreach ($this->materialLines($stop, $rate) as $material) {
                $lines[] = $material;
            }

            if (count($lines) > $linesBefore) {
                $billedStops[] = $stop;
            }
        }

        $slips = $command->includeWaste ? $this->billableSlips($contract, $command) : collect();

        foreach ($slips as $slip) {
            if (Decimal::isPositive((string) $slip->disposal_cost)) {
                $lines[] = $this->line('waste', (string) __('green_care.billing.waste', ['code' => $slip->waste_code, 'kg' => number_format((float) $slip->green_waste_weight_kg, 2, '.', ''), 'slip' => $slip->slip_number]), '1', (string) $slip->disposal_cost, $rate);
            }
        }

        if ($lines === []) {
            throw GreenCareException::because('nothing_to_bill');
        }

        $net = '0';

        foreach ($lines as $line) {
            $net = Decimal::add($net, $line['net']);
        }

        $net = Decimal::round2($net);
        $vat = Decimal::round2(Decimal::div(Decimal::mul($net, $rate), '100'));
        $withholding = null;

        if ($withholdingRule !== null) {
            $amount = Decimal::round2(Decimal::div(Decimal::mul($net, (string) $withholdingRule['percent']), '100'));
            $withholding = [
                'applies' => true,
                'percent' => $withholdingRule['percent'],
                'base' => $net,
                'amount' => $amount,
                'type' => $withholdingRule['type'],
                'reason' => $withholdingRule['reason'],
                'legal_basis' => $withholdingRule['legal_basis'],
            ];
        }

        return [
            'lines' => $lines,
            'total_net' => $net,
            'total_vat' => $vat,
            'withholding' => $withholding,
            'net_payable' => Decimal::round2(Decimal::sub(Decimal::add($net, $vat), $withholding['amount'] ?? '0')),
            'jurisdiction' => $contract->jurisdiction,
            'condominium' => $contract->is_condominium ? [
                'fiscal_code' => $contract->condominium_fiscal_code,
                'administrator_name' => $this->administratorName($contract),
            ] : null,
            'stops' => collect($billedStops),
            'slips' => $slips->filter(static fn (GreenWasteSlip $slip): bool => Decimal::isPositive((string) $slip->disposal_cost))->values(),
            'installment_number' => $installmentNumber,
            'snow_standby' => $snow,
        ];
    }

    /**
     * Number of installments of the season by frequency.
     */
    public function installmentCount(SeasonalMaintenanceContract $contract): int
    {
        $start = $contract->season_start_date;
        $end = $contract->season_end_date;
        $months = max(1, ($end->year - $start->year) * 12 + ($end->month - $start->month) + 1);

        return match ($contract->installment_frequency) {
            InstallmentFrequency::Monthly => $months,
            InstallmentFrequency::Quarterly => (int) ceil($months / 3),
            InstallmentFrequency::SeasonalSplit => min(2, $months),
        };
    }

    /**
     * @return array{number: int, total: int, amount: string}|null
     */
    private function nextInstallment(SeasonalMaintenanceContract $contract): ?array
    {
        $fee = Decimal::normalize((string) $contract->fixed_fee_amount);
        $total = $this->installmentCount($contract);
        $number = $contract->installments_billed + 1;

        if (! Decimal::isPositive($fee) || $number > $total) {
            return null;
        }

        $base = Decimal::round2(Decimal::div($fee, (string) $total));
        $amount = $number === $total ? Decimal::sub($fee, Decimal::mul($base, (string) ($total - 1))) : $base;

        return ['number' => $number, 'total' => $total, 'amount' => Decimal::round2($amount)];
    }

    /**
     * @return Collection<int, CrewRouteStop>
     */
    private function billableStops(SeasonalMaintenanceContract $contract, GenerateGreenCareBillingCommand $command): Collection
    {
        return CrewRouteStop::query()
            ->where('contract_id', $contract->id)
            ->where('billing_status', BillingStatus::ReadyToInvoice->value)
            ->whereNull('invoice_id')
            ->whereNotNull('actual_checkout_at')
            ->when($command->periodStart !== null, static fn ($query) => $query->where('actual_checkout_at', '>=', $command->periodStart->copy()->startOfDay()))
            ->when($command->periodEnd !== null, static fn ($query) => $query->where('actual_checkout_at', '<=', $command->periodEnd->copy()->endOfDay()))
            ->orderBy('actual_checkout_at')
            ->get();
    }

    /**
     * @return Collection<int, GreenWasteSlip>
     */
    private function billableSlips(SeasonalMaintenanceContract $contract, GenerateGreenCareBillingCommand $command): Collection
    {
        return GreenWasteSlip::query()
            ->where('site_id', $contract->site_id)
            ->where('is_billable_to_customer', true)
            ->whereNull('invoice_id')
            ->when($command->periodStart !== null, static fn ($query) => $query->where('disposal_date', '>=', $command->periodStart->toDateString()))
            ->when($command->periodEnd !== null, static fn ($query) => $query->where('disposal_date', '<=', $command->periodEnd->toDateString()))
            ->orderBy('disposal_date')
            ->get();
    }

    /**
     * Plants and materials of a billed stop, at the sale price of the catalog.
     *
     * @return list<Line>
     */
    private function materialLines(CrewRouteStop $stop, string $rate): array
    {
        $lines = [];

        foreach ($stop->materials_and_plants_used ?? [] as $material) {
            $product = Product::query()->find((string) ($material['product_id'] ?? ''));
            $price = $product === null ? '0' : Decimal::normalize((string) $product->sale_price);

            if ($product === null || ! Decimal::isPositive($price)) {
                continue;
            }

            $line = $this->line('material', (string) $product->name, Decimal::normalize((string) $material['quantity']), $price, $rate);
            $line['product_id'] = (string) $product->id;
            $lines[] = $line;
        }

        return $lines;
    }

    private function hourlyRate(SeasonalMaintenanceContract $contract): string
    {
        $own = Decimal::normalize((string) $contract->time_and_material_hourly_rate);

        if (Decimal::isPositive($own)) {
            return $own;
        }

        $regia = $this->strategies->for($contract->jurisdiction)->regiaHourlyRates();

        return Decimal::normalize((string) ($regia['gardener'] ?? 0));
    }

    private function administratorName(SeasonalMaintenanceContract $contract): ?string
    {
        $administrator = $contract->condominiumAdministrator;

        if ($administrator === null) {
            return null;
        }

        $name = trim((string) ($administrator->company_name ?: trim($administrator->first_name.' '.$administrator->last_name)));

        return $name === '' ? null : $name;
    }

    /**
     * @return Line
     */
    private function line(string $kind, string $description, string $quantity, string $unitPrice, string $rate): array
    {
        return [
            'kind' => $kind,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $rate,
            'net' => Decimal::round2(Decimal::mul($quantity, $unitPrice)),
        ];
    }
}
