<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Localization;

use Carbon\CarbonImmutable;
use Modules\DataExport\Services\ExcelCsvWriter;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\VatRegisterService;

/**
 * Libro registro de IVA in the layout of the Spanish SII (Suministro Inmediato de Informacion): the issued invoices
 * book (Facturas Emitidas) and the received invoices book (Facturas Recibidas), one row per invoice and VAT rate.
 * Dates are dd-mm-yyyy and amounts use a decimal point as in the SII messages. Credit notes (negative amounts) are
 * reported as rectifying invoices (R1).
 */
final readonly class SiiVatBookExporter
{
    /** @var list<string> */
    private const array SalesColumns = [
        'Ejercicio', 'Periodo', 'TipoFactura', 'FechaExpedicionFacturaEmisor', 'NumSerieFacturaEmisor', 'ClaveRegimenEspecialOTrascendencia',
        'TipoNoExenta', 'TipoImpositivo', 'BaseImponible', 'CuotaRepercutida', 'ContraparteNombre', 'ContraparteNIF', 'ContraparteCodigoPais', 'ContraparteIDOtro',
    ];

    /** @var list<string> */
    private const array PurchasesColumns = [
        'Ejercicio', 'Periodo', 'TipoFactura', 'FechaExpedicionFacturaEmisor', 'NumSerieFacturaEmisor', 'FechaRegContable',
        'ClaveRegimenEspecialOTrascendencia', 'TipoImpositivo', 'BaseImponible', 'CuotaSoportada', 'CuotaDeducible', 'ContraparteNombre',
        'ContraparteNIF', 'ContraparteCodigoPais', 'ContraparteIDOtro',
    ];

    public function __construct(private ExcelCsvWriter $writer) {}

    public function fileName(string $type, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $book = $type === VatRegisterService::SALES ? 'facturas-emitidas' : 'facturas-recibidas';

        return "sii-{$book}-{$from->toDateString()}-{$to->toDateString()}.csv";
    }

    /**
     * @param  array{lines: list<array<string, mixed>>, totals: array{taxable: string, tax: string}}  $register  as returned by VatRegisterService::linesOf
     */
    public function csv(array $register, string $type): string
    {
        $isSales = $type === VatRegisterService::SALES;
        $rows = [];

        foreach ($register['lines'] as $line) {
            $posting = CarbonImmutable::parse((string) $line['posting_date']);
            $taxable = (string) $line['taxable_amount'];
            $tax = (string) $line['vat_amount'];
            $reverseCharge = str_contains(strtolower((string) ($line['vat_treatment'] ?? '')), 'reverse');
            $counterparty = $this->counterparty($line);
            $common = [
                $posting->format('Y'),
                $posting->format('m'),
                Decimal::isNegative($taxable) ? 'R1' : 'F1',
                CarbonImmutable::parse((string) $line['document_date'])->format('d-m-Y'),
                (string) $line['document_number'],
            ];

            $rows[] = $isSales
                ? [...$common, '01', $reverseCharge ? 'S2' : 'S1', $this->rate((string) $line['vat_rate']), Decimal::round2($taxable), Decimal::round2($tax), (string) ($line['partner_name'] ?? ''), ...$counterparty]
                : [...$common, $posting->format('d-m-Y'), '01', $this->rate((string) $line['vat_rate']), Decimal::round2($taxable), Decimal::round2($tax), Decimal::round2($tax), (string) ($line['partner_name'] ?? ''), ...$counterparty];
        }

        return $this->writer->write($isSales ? self::SalesColumns : self::PurchasesColumns, $rows);
    }

    /**
     * The counterparty identification of the SII: the NIF of a Spanish counterparty, or the country code and tax number
     * (IDOtro) of a foreign one. A counterparty without tax identifier leaves the three columns empty.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: string, 1: string, 2: string}
     */
    private function counterparty(array $line): array
    {
        $taxId = strtoupper((string) preg_replace('/[\s.\-]/', '', (string) ($line['partner_tax_id'] ?? '')));
        $country = strtoupper((string) ($line['partner_country'] ?? ''));

        if ($taxId === '') {
            return ['', '', ''];
        }

        if ($country === '' || $country === 'ES') {
            return [str_starts_with($taxId, 'ES') ? substr($taxId, 2) : $taxId, '', ''];
        }

        return ['', $country, str_starts_with($taxId, $country) ? substr($taxId, 2) : $taxId];
    }

    private function rate(string $rate): string
    {
        return number_format((float) $rate, 2, '.', '');
    }
}
