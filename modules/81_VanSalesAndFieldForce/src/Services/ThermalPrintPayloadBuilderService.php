<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Services;

use Modules\VanSalesAndFieldForce\Domain\Enums\ThermalPrinterProfile;
use Modules\VanSalesAndFieldForce\Domain\ValueObjects\ThermalPrintPayload;
use Modules\VanSalesAndFieldForce\Models\VanVisitOutcome;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;

/**
 * Builds Bluetooth portable thermal printer payloads in ESC/POS (80mm) and ZPL II (104mm)
 * for Zebra, Bixolon, and Epson belt printers.
 */
final class ThermalPrintPayloadBuilderService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function buildForVisit(VanVisitOutcome $visit, VanWarehouse $van, array $context = []): ThermalPrintPayload
    {
        $docNumber = (string) ($visit->definitive_document_number ?? 'DRAFT');
        $contact = $visit->contact;
        $customerName = (string) ($context['customer_name'] ?? ($contact !== null ? $contact->company_name : 'Customer'));
        $companyName = (string) ($context['company_name'] ?? 'FatturaFacile ERP');
        $currency = (string) $visit->currency;
        $dateStr = $visit->visited_at->format('d/m/Y H:i');

        $linesText = [];
        foreach ((array) ($visit->sale_lines ?? []) as $line) {
            $desc = substr((string) ($line['description'] ?? 'Item'), 0, 24);
            $qty = number_format((float) ($line['quantity'] ?? 0), 3, '.', '');
            $unit = (string) ($line['unit_measure'] ?? 'kg');
            $tot = number_format((float) ($line['line_total'] ?? 0), 2, '.', '');
            $lot = ! empty($line['lot_number']) ? ' [L:'.$line['lot_number'].']' : '';
            $linesText[] = sprintf('%-24s %6s%s %7s %s%s', $desc, $qty, $unit, $tot, $currency, $lot);
        }

        $emptiesSummary = [];
        foreach ((array) ($visit->empties_movements ?? []) as $emp) {
            $ename = substr((string) ($emp['item_name'] ?? 'Vuoti'), 0, 20);
            $del = (int) ($emp['delivered_qty'] ?? 0);
            $ret = (int) ($emp['returned_qty'] ?? 0);
            $emptiesSummary[] = sprintf('%s: +%d / -%d', $ename, $del, $ret);
        }

        $docTypeLabel = $visit->document_type !== null ? $visit->document_type->value : 'van_doc';
        $humanLines = [
            $companyName,
            sprintf('VAN: %s (%s)', $van->license_plate, $van->code),
            sprintf('DOC: %s (%s)', $docNumber, $docTypeLabel),
            sprintf('DATA: %s', $dateStr),
            sprintf('CLIENTE: %s', $customerName),
            str_repeat('-', 42),
            ...$linesText,
            str_repeat('-', 42),
            sprintf('IMPONIBILE: %10s %s', number_format((float) $visit->total_net, 2, '.', ''), $currency),
            sprintf('IVA / MWST: %10s %s', number_format((float) $visit->total_tax, 2, '.', ''), $currency),
            sprintf('TOTALE:     %10s %s', number_format((float) $visit->total_gross, 2, '.', ''), $currency),
            sprintf('VUOTI NET:  %10s %s', number_format((float) $visit->empties_net_deposit_amount, 2, '.', ''), $currency),
            sprintf('INCASSATO:  %10s %s', number_format((float) $visit->total_collected_amount, 2, '.', ''), $currency),
        ];

        if ($emptiesSummary !== []) {
            $humanLines[] = 'VUOTI (MOD 151): '.implode('; ', $emptiesSummary);
        }
        if ($visit->qr_bill_generated && $visit->qr_bill_reference) {
            $humanLines[] = 'CH QR-BILL REF: '.$visit->qr_bill_reference;
        }
        if ($visit->sdi_transmission_status !== 'not_applicable') {
            $humanLines[] = 'SDI STATUS: '.strtoupper($visit->sdi_transmission_status);
        }
        $humanLines[] = 'FIRMA CLIENTE: ___________________________';

        $humanPreview = implode("\n", $humanLines);

        // ESC/POS 80mm stream (ESC @ init, ESC E bold header, LF lines, GS V cut)
        $escInit = "\\x1B\\x40\\x1B\\x61\\x01\\x1B\\x45\\x01{$companyName}\\x1B\\x45\\x00\\n";
        $escBody = '\\x1B\\x61\\x00'.str_replace("\n", '\\n', $humanPreview).'\\n\\n\\n\\x1D\\x56\\x41\\x00';
        $escposCommands = $escInit.$escBody;

        // ZPL II 104mm (832 dots @ 203 dpi)
        $y = 40;
        $zplParts = [
            '^XA',
            '^PW832',
            '^CF0,28',
            "^FO30,{$y}^FD{$companyName}^FS",
        ];
        $y += 38;
        $zplParts[] = '^CF0,22';
        foreach (array_slice($humanLines, 1) as $line) {
            $safe = str_replace(['^', '~'], [' ', ' '], $line);
            $zplParts[] = "^FO30,{$y}^FD{$safe}^FS";
            $y += 28;
        }
        $zplParts[] = '^XZ';
        $zplCommands = implode("\n", $zplParts);

        $profile = $visit->printer_format;

        return new ThermalPrintPayload(
            profile: $profile->value,
            printerBrand: $van->printer_brand,
            paperWidthMm: $profile === ThermalPrinterProfile::Zpl104mm ? '104mm' : '80mm',
            documentNumber: $docNumber,
            escposCommands: $escposCommands,
            zplCommands: $zplCommands,
            humanPreview: $humanPreview,
        );
    }
}
