<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Contracts\PdfGenerator;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Exceptions\VatSettlementException;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\ItalianVatPeriod;
use Modules\GeneralLedger\Services\Tax\LipeData;
use Modules\GeneralLedger\Services\Tax\LipeXmlBuilder;
use Modules\GeneralLedger\Services\Tax\SwissEstvXmlBuilder;
use Modules\GeneralLedger\Services\Tax\VatAccountResolver;

/**
 * Files built from a VAT settlement: LIPE XML and F24 data for Italy, MWST statement for Switzerland.
 */
final class VatFilingExportService
{
    /** Boxes of the ESTV effective-method form for the rates in force. */
    private const array SWISS_RATE_BOXES = ['8.1' => '303', '2.6' => '313', '3.8' => '343'];

    public function __construct(
        private readonly VatAccountResolver $accountResolver,
        private readonly PdfGenerator $pdfGenerator,
    ) {}

    /**
     * @return array{file_name: string, content: string}
     */
    public function lipe(VatSettlement $settlement): array
    {
        $this->assertCountry($settlement, 'IT', 'The LIPE communication concerns Italian VAT settlements only.');

        $tenant = Tenant::query()->findOrFail($settlement->tenant_id);
        $vatNumber = $this->alphanumeric($tenant->vat_number);

        if (str_starts_with($vatNumber, 'IT')) {
            $vatNumber = substr($vatNumber, 2);
        }

        if (strlen($vatNumber) !== 11) {
            throw VatSettlementException::invalid('The Italian VAT number (11 digits) of the company is missing.');
        }

        $taxCode = trim((string) $tenant->fiscal_code) === '' ? $vatNumber : $this->alphanumeric($tenant->fiscal_code);
        $settings = $this->accountResolver->settingsOf((string) $settlement->tenant_id);
        $declarant = $this->alphanumeric($settings->declarant_tax_code);
        $from = $settlement->period_from;
        $to = $settlement->period_to;
        $monthly = ItalianVatPeriod::isMonthly($from, $to);

        $data = new LipeData(
            taxCode: $taxCode,
            vatNumber: $vatNumber,
            year: (int) $from->format('Y'),
            month: $monthly ? (int) $from->format('n') : null,
            quarter: $monthly ? null : ItalianVatPeriod::quarter($from),
            declarantTaxCode: $declarant === '' || $declarant === $taxCode ? null : $declarant,
            declarantRoleCode: $settings->declarant_role_code ?: '1',
            activeOperations: (string) $settlement->taxable_turnover,
            passiveOperations: (string) $settlement->passive_operations,
            outputVat: (string) $settlement->output_vat_amount,
            inputVat: (string) $settlement->input_vat_amount,
            previousDebit: (string) $settlement->previous_debit,
            previousCredit: (string) $settlement->prior_credit_used,
            interest: (string) $settlement->interest_amount,
            payable: (string) $settlement->payable_amount,
        );

        return ['file_name' => LipeXmlBuilder::fileName($data), 'content' => LipeXmlBuilder::build($data)];
    }

    /**
     * @return array{section: string, tax_code: ?string, reference_year: int, amount: string, payment_due: bool, carried_forward: string, summary: string}
     */
    public function f24(VatSettlement $settlement): array
    {
        $this->assertCountry($settlement, 'IT', 'The F24 data concern Italian VAT settlements only.');

        $from = $settlement->period_from;
        $to = $settlement->period_to;
        $year = (int) $from->format('Y');
        $payable = (string) $settlement->payable_amount;
        $taxCode = ItalianVatPeriod::f24TaxCode($from, $to);
        $paymentDue = Decimal::isPositive($payable) && ! $settlement->below_minimum_payment;

        if ($paymentDue) {
            $summary = sprintf('F24 sezione Erario: codice tributo %s, anno %d, importo %s.', $taxCode, $year, LipeXmlBuilder::formatAmount($payable));
        } elseif ($settlement->below_minimum_payment) {
            $summary = sprintf('Nessun versamento: debito %s inferiore al minimo, riportato al periodo successivo.', LipeXmlBuilder::formatAmount($payable));
        } else {
            $summary = sprintf('Nessun versamento: credito %s riportato al periodo successivo.', LipeXmlBuilder::formatAmount(Decimal::neg($payable)));
        }

        return [
            'section' => 'erario',
            'tax_code' => $paymentDue ? $taxCode : null,
            'reference_year' => $year,
            'amount' => $paymentDue ? Decimal::round2($payable) : '0.00',
            'payment_due' => $paymentDue,
            'carried_forward' => $paymentDue ? '0.00' : Decimal::round2(Decimal::abs($payable)),
            'summary' => $summary,
        ];
    }

    /**
     * The lines of the Swiss statement.
     *
     * @return list<array{box: string, label: string, taxable: ?string, rate: ?string, amount: ?string}>
     */
    public function swissStatementRows(VatSettlement $settlement): array
    {
        $this->assertCountry($settlement, 'CH', 'The MWST statement concerns Swiss VAT settlements only.');

        $turnover = (string) $settlement->turnover;
        $taxable = (string) $settlement->taxable_turnover;
        $payable = (string) $settlement->payable_amount;

        $rows = [
            ['box' => '200', 'label' => "Cifra d'affari totale", 'taxable' => null, 'rate' => null, 'amount' => Decimal::round2($turnover)],
            ['box' => '289', 'label' => 'Prestazioni non imponibili', 'taxable' => null, 'rate' => null, 'amount' => Decimal::round2(Decimal::sub($turnover, $taxable))],
            ['box' => '299', 'label' => "Cifra d'affari imponibile", 'taxable' => null, 'rate' => null, 'amount' => Decimal::round2($taxable)],
        ];

        foreach ($settlement->rate_breakdown ?? [] as $rate) {
            $rows[] = [
                'box' => self::SWISS_RATE_BOXES[$rate['rate']] ?? '',
                'label' => "Imposta all'aliquota {$rate['rate']}%",
                'taxable' => $rate['taxable'],
                'rate' => $rate['rate'],
                'amount' => $rate['tax'],
            ];
        }

        $rows[] = ['box' => '399', 'label' => 'Totale imposta dovuta', 'taxable' => null, 'rate' => null, 'amount' => Decimal::round2((string) $settlement->output_vat_amount)];
        $rows[] = ['box' => '479', 'label' => 'Imposta precedente deducibile', 'taxable' => null, 'rate' => null, 'amount' => Decimal::round2((string) $settlement->input_vat_amount)];

        if (Decimal::isPositive((string) $settlement->prior_credit_used)) {
            $rows[] = ['box' => '', 'label' => 'Credito del rendiconto precedente', 'taxable' => null, 'rate' => null, 'amount' => Decimal::round2((string) $settlement->prior_credit_used)];
        }

        $rows[] = Decimal::isNegative($payable)
            ? ['box' => '510', 'label' => 'Credito del contribuente', 'taxable' => null, 'rate' => null, 'amount' => Decimal::round2(Decimal::neg($payable))]
            : ['box' => '500', 'label' => "Importo dovuto all'AFC", 'taxable' => null, 'rate' => null, 'amount' => Decimal::round2($payable)];

        return $rows;
    }

    /**
     * @return array{file_name: string, content: string}
     */
    public function swissStatementCsv(VatSettlement $settlement): array
    {
        $lines = ['Cifra;Voce;Imponibile;Aliquota;Importo'];

        foreach ($this->swissStatementRows($settlement) as $row) {
            $lines[] = implode(';', [
                $row['box'],
                self::csvText($row['label']),
                self::csvAmount($row['taxable']),
                $row['rate'] ?? '',
                self::csvAmount($row['amount']),
            ]);
        }

        return [
            'file_name' => "mwst-{$settlement->settlement_period}.csv",
            'content' => "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n",
        ];
    }

    /**
     * @return array{file_name: string, content: string}
     */
    public function swissStatementPdf(VatSettlement $settlement): array
    {
        $rows = $this->swissStatementRows($settlement);
        $tenant = Tenant::query()->findOrFail($settlement->tenant_id);

        return [
            'file_name' => "mwst-{$settlement->settlement_period}.pdf",
            'content' => $this->pdfGenerator->render('general-ledger::vat-statement', [
                'companyName' => $tenant->legal_name ?: $tenant->name,
                'uidNumber' => $tenant->uid_number ?: $tenant->vat_number,
                'settlement' => $settlement,
                'rows' => $rows,
                'periodFrom' => CarbonImmutable::parse($settlement->period_from)->format('d.m.Y'),
                'periodTo' => CarbonImmutable::parse($settlement->period_to)->format('d.m.Y'),
            ]),
        ];
    }

    /**
     * @return array{file_name: string, content: string}
     */
    public function swissEstvXml(VatSettlement $settlement): array
    {
        $this->assertCountry($settlement, 'CH', 'The eCH-0217 XML export concerns Swiss VAT settlements only.');
        $tenant = Tenant::query()->findOrFail($settlement->tenant_id);

        return [
            'file_name' => SwissEstvXmlBuilder::fileName($settlement),
            'content' => SwissEstvXmlBuilder::build($settlement, $tenant),
        ];
    }

    /**
     * @param  array{lines: list<array<string, mixed>>, totals: array{taxable: string, tax: string}}  $register
     */
    public function registerCsv(array $register): string
    {
        $lines = ['Protocollo;Data registrazione;Data documento;Numero documento;Tipo;Controparte;Imponibile;Aliquota;Imposta;Trattamento'];

        foreach ($register['lines'] as $line) {
            $lines[] = implode(';', [
                (string) ($line['protocol_number'] ?? ''),
                $line['posting_date'],
                $line['document_date'],
                self::csvText((string) $line['document_number']),
                self::csvText((string) $line['document_type']),
                self::csvText((string) $line['partner_name']),
                self::csvAmount($line['taxable_amount']),
                $line['vat_rate'],
                self::csvAmount($line['vat_amount']),
                (string) $line['vat_treatment'],
            ]);
        }

        return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
    }

    private function assertCountry(VatSettlement $settlement, string $country, string $message): void
    {
        if ($settlement->country !== $country) {
            throw VatSettlementException::invalid($message);
        }
    }

    private function alphanumeric(?string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    private static function csvAmount(?string $value): string
    {
        return $value === null ? '' : str_replace('.', ',', Decimal::round2($value));
    }

    /**
     * A text cell: quoted when it holds a delimiter, quote or line break, and neutralised when a spreadsheet could read
     * it as a formula.
     */
    private static function csvText(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'".$value;
        }

        return preg_match('/[;"\r\n]/', $value) === 1 ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
