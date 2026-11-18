<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Localization;

use Carbon\CarbonImmutable;
use Modules\DataExport\Services\ExcelCsvWriter;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\VatRegisterService;

/**
 * Periodic VAT return data of France (CA3), Spain (Modelo 303 and 390) and Germany (UStVA), computed from the posted
 * ledger through the sales and purchases VAT registers. Every return is a list of boxes (`code`, English `label`,
 * `amount`): the boxes named `NN_tax` hold the tax of the base box `NN`.
 *
 * What the ledger knows is the VAT rate, the taxable amount, the tax and the reverse charge flag of each line. It does
 * not know the nature of an exemption, so the sales taxed at 0% (exports, intra-community supplies, exempt) land in one
 * box of each return, and every reverse charge purchase is reported as one reverse charge acquisition (France line 15,
 * Spain boxes 10 and 11, Germany Kz 46 and 47). The result is the data of the return, not a filing in the format of the
 * tax authority.
 *
 * @phpstan-type Box array{code: string, label: string, amount: string}
 * @phpstan-type ReturnData array{form: string, country: string, from: string, to: string, boxes: list<Box>, vat_due: string, vat_credit: string, warnings: list<string>}
 */
final readonly class CountryVatReturnService
{
    public function __construct(
        private VatRegisterService $registers,
        private ExcelCsvWriter $writer,
    ) {}

    /**
     * @return ReturnData
     */
    public function ca3(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, string $previousCredit = '0'): array
    {
        $data = $this->aggregate($tenantId, $from, $to);
        $reverse = $data['purchases']['reverse'];
        $boxes = [];
        $gross = '0';

        $this->push($boxes, 'A', 'Total sales and services (excluding VAT)', $data['sales']['total_base']);
        $this->push($boxes, '05', 'Other non-taxable operations (sales at 0%)', $data['sales']['exempt']);

        $rates = $data['sales']['rates'];

        foreach ([['08', '20.00', 'standard rate 20%'], ['09', '5.50', 'reduced rate 5.5%'], ['9B', '10.00', 'reduced rate 10%'], ['10', '2.10', 'special rate 2.1%']] as [$code, $rate, $label]) {
            $row = $rates[$rate] ?? ['base' => '0', 'tax' => '0'];
            $this->push($boxes, $code, "Taxable base, {$label}", $row['base']);
            $this->push($boxes, "{$code}_tax", "VAT due, {$label}", $row['tax']);
            $gross = Decimal::add($gross, $row['tax']);
            unset($rates[$rate]);
        }

        [$otherBase, $otherTax] = $this->sumRows($rates);
        $this->push($boxes, '11', 'Taxable base, other rates', $otherBase);
        $this->push($boxes, '11_tax', 'VAT due, other rates', $otherTax);
        $this->push($boxes, '15', 'VAT due on reverse charge acquisitions', $reverse['tax']);
        $gross = Decimal::add(Decimal::add($gross, $otherTax), $reverse['tax']);
        $this->push($boxes, '16', 'Total gross VAT due', $gross);
        $this->push($boxes, '20', 'Deductible VAT on other goods and services', $data['purchases']['tax']);
        $this->push($boxes, '21', 'Other deductible VAT (reverse charge)', $reverse['tax']);
        $this->push($boxes, '22', 'Credit carried over', $previousCredit);
        $deductible = Decimal::add(Decimal::add($data['purchases']['tax'], $reverse['tax']), $previousCredit);
        $this->push($boxes, '23', 'Total deductible VAT', $deductible);
        $net = Decimal::sub($gross, $deductible);
        $this->push($boxes, '25', 'VAT credit', Decimal::isNegative($net) ? Decimal::neg($net) : '0');
        $this->push($boxes, '28', 'Net VAT due', Decimal::isPositive($net) ? $net : '0');

        return $this->result('CA3', 'FR', $from, $to, $boxes, $net, $data['warnings']);
    }

    /**
     * @return ReturnData
     */
    public function modelo303(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, string $previousCredit = '0'): array
    {
        $data = $this->aggregate($tenantId, $from, $to);
        $reverse = $data['purchases']['reverse'];
        $boxes = [];
        $accrued = '0';
        $rates = $data['sales']['rates'];

        foreach (['01' => ['4.00', '03', '4%'], '04' => ['10.00', '06', '10%'], '07' => ['21.00', '09', '21%']] as $baseCode => [$rate, $taxCode, $label]) {
            $row = $rates[$rate] ?? ['base' => '0', 'tax' => '0'];
            $this->push($boxes, $baseCode, "Taxable base, {$label}", $row['base']);
            $this->push($boxes, $taxCode, "Output VAT, {$label}", $row['tax']);
            $accrued = Decimal::add($accrued, $row['tax']);
            unset($rates[$rate]);
        }

        [$otherBase, $otherTax] = $this->sumRows($rates);

        if ($rates !== []) {
            $data['warnings'][] = 'Sales at rates other than 4%, 10% and 21% are included in box 27 and their base is listed in box 14.';
        }

        $this->push($boxes, '14', 'Taxable base, other rates', $otherBase);
        $this->push($boxes, '10', 'Taxable base, reverse charge acquisitions', $reverse['base']);
        $this->push($boxes, '11', 'Output VAT, reverse charge acquisitions', $reverse['tax']);
        $accrued = Decimal::add(Decimal::add($accrued, $otherTax), $reverse['tax']);
        $this->push($boxes, '27', 'Total output VAT', $accrued);
        $this->push($boxes, '28', 'Taxable base of deductible domestic input VAT', $data['purchases']['base']);
        $this->push($boxes, '29', 'Deductible domestic input VAT', $data['purchases']['tax']);
        $this->push($boxes, '36', 'Taxable base of deductible reverse charge acquisitions', $reverse['base']);
        $this->push($boxes, '37', 'Deductible VAT of reverse charge acquisitions', $reverse['tax']);
        $deductible = Decimal::add($data['purchases']['tax'], $reverse['tax']);
        $this->push($boxes, '45', 'Total VAT to deduct', $deductible);
        $result = Decimal::sub($accrued, $deductible);
        $this->push($boxes, '46', 'Result of the general regime', $result);
        $this->push($boxes, '60', 'Exports and other sales at 0%', $data['sales']['exempt']);
        $this->push($boxes, '64', 'Sum of results', $result);
        $this->push($boxes, '67', 'VAT to offset from previous periods', $previousCredit);
        $final = Decimal::sub($result, $previousCredit);
        $this->push($boxes, '71', 'Result of the settlement', $final);

        return $this->result('303', 'ES', $from, $to, $boxes, $final, $data['warnings']);
    }

    /**
     * The annual summary: the four quarterly 303 returns and the whole year computed as one period.
     *
     * @return array{form: string, country: string, year: int, quarters: list<array<string, mixed>>, annual: array<string, mixed>}
     */
    public function modelo390(string $tenantId, int $year): array
    {
        $quarters = [];

        for ($quarter = 1; $quarter <= 4; $quarter++) {
            $start = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay();
            $quarters[] = ['quarter' => $quarter, ...$this->modelo303($tenantId, $start, $start->addMonths(2)->endOfMonth()->startOfDay())];
        }

        return [
            'form' => '390',
            'country' => 'ES',
            'year' => $year,
            'quarters' => $quarters,
            'annual' => $this->modelo303($tenantId, CarbonImmutable::create($year, 1, 1)->startOfDay(), CarbonImmutable::create($year, 12, 31)->startOfDay()),
        ];
    }

    /**
     * @return ReturnData
     */
    public function ustva(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $data = $this->aggregate($tenantId, $from, $to);
        $reverse = $data['purchases']['reverse'];
        $boxes = [];
        $output = '0';
        $rates = $data['sales']['rates'];

        foreach ([['81', '19.00', '19%'], ['86', '7.00', '7%']] as [$code, $rate, $label]) {
            $row = $rates[$rate] ?? ['base' => '0', 'tax' => '0'];
            $this->push($boxes, $code, "Taxable sales at {$label}", $row['base']);
            $this->push($boxes, "{$code}_tax", "Output tax at {$label}", $row['tax']);
            $output = Decimal::add($output, $row['tax']);
            unset($rates[$rate]);
        }

        [$otherBase, $otherTax] = $this->sumRows($rates);
        $this->push($boxes, '35', 'Taxable sales at other rates', $otherBase);
        $this->push($boxes, '35_tax', 'Output tax at other rates', $otherTax);
        $this->push($boxes, '43', 'Tax-free sales with input tax deduction (sales at 0%)', $data['sales']['exempt']);
        $this->push($boxes, '46', 'Reverse charge acquisitions and services (section 13b), taxable base', $reverse['base']);
        $this->push($boxes, '46_tax', 'Reverse charge acquisitions and services (section 13b), tax', $reverse['tax']);
        $totalOutput = Decimal::add(Decimal::add($output, $otherTax), $reverse['tax']);
        $this->push($boxes, '66', 'Input tax from invoices', $data['purchases']['tax']);
        $this->push($boxes, '67', 'Input tax from reverse charge (section 13b)', $reverse['tax']);
        $balance = Decimal::sub($totalOutput, Decimal::add($data['purchases']['tax'], $reverse['tax']));
        $this->push($boxes, '83', 'Remaining VAT prepayment', $balance);

        return $this->result('UStVA', 'DE', $from, $to, $boxes, $balance, $data['warnings']);
    }

    /**
     * The boxes of a return as CSV: code, label, amount.
     *
     * @param  array{boxes: list<Box>}  $return
     */
    public function csv(array $return): string
    {
        return $this->writer->write(
            ['Code', 'Label', 'Amount'],
            array_map(static fn (array $box): array => [$box['code'], $box['label'], $box['amount']], $return['boxes']),
        );
    }

    public function fileName(string $form, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return strtolower($form).'-'.$from->toDateString().'-'.$to->toDateString().'.csv';
    }

    /**
     * @param  array<string, array{base: string, tax: string}>  $rows
     * @return array{0: string, 1: string}
     */
    private function sumRows(array $rows): array
    {
        $base = '0';
        $tax = '0';

        foreach ($rows as $row) {
            $base = Decimal::add($base, $row['base']);
            $tax = Decimal::add($tax, $row['tax']);
        }

        return [$base, $tax];
    }

    /**
     * @return array{sales: array{rates: array<string, array{base: string, tax: string}>, exempt: string, total_base: string}, purchases: array{base: string, tax: string, reverse: array{base: string, tax: string}}, warnings: list<string>}
     */
    private function aggregate(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $warnings = [];
        $sales = ['rates' => [], 'exempt' => '0', 'total_base' => '0'];
        $purchases = ['base' => '0', 'tax' => '0', 'reverse' => ['base' => '0', 'tax' => '0']];

        foreach ($this->registers->linesOf($tenantId, VatRegisterService::SALES, $from, $to)['lines'] as $line) {
            $base = (string) $line['taxable_amount'];
            $rate = $this->rate((string) $line['vat_rate']);
            $sales['total_base'] = Decimal::add($sales['total_base'], $base);

            if ($this->isReverseCharge($line) || Decimal::isZero($rate)) {
                $sales['exempt'] = Decimal::add($sales['exempt'], $base);

                continue;
            }

            $current = $sales['rates'][$rate] ?? ['base' => '0', 'tax' => '0'];
            $sales['rates'][$rate] = ['base' => Decimal::add($current['base'], $base), 'tax' => Decimal::add($current['tax'], (string) $line['vat_amount'])];
        }

        foreach ($this->registers->linesOf($tenantId, VatRegisterService::PURCHASES, $from, $to)['lines'] as $line) {
            $base = (string) $line['taxable_amount'];
            $tax = (string) $line['vat_amount'];

            if (! $this->isReverseCharge($line)) {
                $purchases['base'] = Decimal::add($purchases['base'], $base);
                $purchases['tax'] = Decimal::add($purchases['tax'], $tax);

                continue;
            }

            $rate = $this->rate((string) $line['vat_rate']);

            if (Decimal::isZero($tax) && ! Decimal::isZero($rate)) {
                $tax = Decimal::round2(Decimal::div(Decimal::mul($base, $rate), 100));
            }

            if (Decimal::isZero($tax)) {
                $warnings[] = "Reverse charge purchase {$line['document_number']} has no VAT rate: its tax is not computed.";
            }

            $purchases['reverse']['base'] = Decimal::add($purchases['reverse']['base'], $base);
            $purchases['reverse']['tax'] = Decimal::add($purchases['reverse']['tax'], $tax);
        }

        return [
            'sales' => [
                'rates' => array_map(static fn (array $row): array => ['base' => Decimal::round2($row['base']), 'tax' => Decimal::round2($row['tax'])], $sales['rates']),
                'exempt' => Decimal::round2($sales['exempt']),
                'total_base' => Decimal::round2($sales['total_base']),
            ],
            'purchases' => [
                'base' => Decimal::round2($purchases['base']),
                'tax' => Decimal::round2($purchases['tax']),
                'reverse' => ['base' => Decimal::round2($purchases['reverse']['base']), 'tax' => Decimal::round2($purchases['reverse']['tax'])],
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isReverseCharge(array $line): bool
    {
        return str_contains(strtolower((string) ($line['vat_treatment'] ?? '')), 'reverse');
    }

    private function rate(string $rate): string
    {
        return number_format((float) $rate, 2, '.', '');
    }

    /**
     * @param  list<Box>  $boxes
     */
    private function push(array &$boxes, string $code, string $label, string $amount): void
    {
        $boxes[] = ['code' => $code, 'label' => $label, 'amount' => Decimal::round2($amount)];
    }

    /**
     * @param  list<Box>  $boxes
     * @param  list<string>  $warnings
     * @return ReturnData
     */
    private function result(string $form, string $country, CarbonImmutable $from, CarbonImmutable $to, array $boxes, string $net, array $warnings): array
    {
        return [
            'form' => $form,
            'country' => $country,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'boxes' => $boxes,
            'vat_due' => Decimal::isPositive($net) ? Decimal::round2($net) : '0.00',
            'vat_credit' => Decimal::isNegative($net) ? Decimal::round2(Decimal::neg($net)) : '0.00',
            'warnings' => $warnings,
        ];
    }
}
