<?php

declare(strict_types=1);

namespace Modules\DataMigration\Services;

use App\Models\Tenant;
use App\Support\Security\SafeXmlLoader;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads uploaded source files (.csv, .xlsx, .xml, .zip) into normalized staging rows.
 * Handles XXE-safe bulk FatturaPA / SDI XML archives and multi-entity extraction.
 */
final class MigrationArchiveReader
{
    public const int MAX_ROWS = 10000;

    /**
     * @param  list<string>  $targetEntities
     * @return array{
     *     headers: list<string>,
     *     headers_by_entity: array<string, list<string>>,
     *     sample: list<array<string, string>>,
     *     rows: list<array{entity_type: string, row_index: int, source_record_identifier: ?string, raw_payload: array<string, mixed>}>,
     *     metadata: array<string, mixed>
     * }
     */
    public function read(
        string $filePath,
        string $extension,
        MigrationSourceSystem $sourceSystem,
        array $targetEntities,
        ?Tenant $tenant = null,
    ): array {
        $ext = strtolower(trim($extension));

        if ($ext === 'zip') {
            return $this->readZipArchive($filePath, $sourceSystem, $targetEntities, $tenant);
        }

        if ($ext === 'xml') {
            $xml = (string) file_get_contents($filePath);

            return $this->parseFatturaPaDocuments([['name' => basename($filePath), 'xml' => $xml]], $targetEntities, $tenant);
        }

        return $this->readTabularFile($filePath, $ext, $targetEntities);
    }

    /**
     * @param  list<string>  $targetEntities
     * @return array{
     *     headers: list<string>,
     *     headers_by_entity: array<string, list<string>>,
     *     sample: list<array<string, string>>,
     *     rows: list<array{entity_type: string, row_index: int, source_record_identifier: ?string, raw_payload: array<string, mixed>}>,
     *     metadata: array<string, mixed>
     * }
     */
    private function readTabularFile(string $filePath, string $extension, array $targetEntities): array
    {
        $reader = $extension === 'xlsx'
            ? new XlsxReader
            : new CsvReader(new CsvOptions(FIELD_DELIMITER: $this->detectCsvDelimiter($filePath)));

        $reader->open($filePath);
        $headers = [];
        $rawRows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map($this->stringifyCell(...), $row->toArray());

                    if ($headers === []) {
                        $headers = array_values(array_map(static fn (string $h): string => trim($h, "\xEF\xBB\xBF \t\n\r\0\x0B"), $cells));

                        continue;
                    }

                    if (count(array_filter($cells, static fn (string $cell): bool => $cell !== '')) === 0) {
                        continue;
                    }

                    if (count($rawRows) >= self::MAX_ROWS) {
                        throw ValidationException::withMessages(['file' => [__('data_migration.max_rows_exceeded', ['max' => self::MAX_ROWS])]]);
                    }

                    $rawRows[] = $cells;
                }

                break;
            }
        } finally {
            $reader->close();
        }

        if ($headers === []) {
            throw ValidationException::withMessages(['file' => [__('data_migration.empty_file')]]);
        }

        $primaryEntity = $targetEntities[0] ?? MigrationTargetEntity::Customers->value;
        $rows = [];
        $sample = [];

        foreach ($rawRows as $index => $cells) {
            $assoc = [];
            foreach ($headers as $pos => $header) {
                if ($header === '') {
                    continue;
                }
                $assoc[$header] = $cells[$pos] ?? '';
            }

            if ($index < 5) {
                $sample[] = $assoc;
            }

            $rows[] = [
                'entity_type' => $primaryEntity,
                'row_index' => $index + 1,
                'source_record_identifier' => (string) ($cells[0] ?? ($index + 1)),
                'raw_payload' => $assoc,
            ];
        }

        return [
            'headers' => array_values(array_filter($headers, static fn (string $h): bool => $h !== '')),
            'headers_by_entity' => [$primaryEntity => array_values(array_filter($headers, static fn (string $h): bool => $h !== ''))],
            'sample' => $sample,
            'rows' => $rows,
            'metadata' => [
                'format' => $extension,
                'row_count' => count($rows),
            ],
        ];
    }

    /**
     * @param  list<string>  $targetEntities
     * @return array{
     *     headers: list<string>,
     *     headers_by_entity: array<string, list<string>>,
     *     sample: list<array<string, string>>,
     *     rows: list<array{entity_type: string, row_index: int, source_record_identifier: ?string, raw_payload: array<string, mixed>}>,
     *     metadata: array<string, mixed>
     * }
     */
    private function readZipArchive(
        string $filePath,
        MigrationSourceSystem $sourceSystem,
        array $targetEntities,
        ?Tenant $tenant,
    ): array {
        $zip = new ZipArchive;

        if ($zip->open($filePath) !== true) {
            throw ValidationException::withMessages(['file' => [__('data_migration.invalid_zip_archive')]]);
        }

        $xmlDocuments = [];
        $tabularTempFiles = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = (string) $zip->getNameIndex($i);
                if ($entryName === '' || str_ends_with($entryName, '/')) {
                    continue;
                }

                // Prevent ZipSlip traversal
                if (str_contains($entryName, '..')) {
                    continue;
                }

                $lower = strtolower($entryName);
                $content = $zip->getFromIndex($i);

                if ($content === false || $content === '') {
                    continue;
                }

                if (str_ends_with($lower, '.xml')) {
                    $xmlDocuments[] = ['name' => basename($entryName), 'xml' => $content];
                } elseif (str_ends_with($lower, '.csv') || str_ends_with($lower, '.xlsx')) {
                    $tmpPath = tempnam(sys_get_temp_dir(), 'ff_mig_');
                    if ($tmpPath !== false) {
                        file_put_contents($tmpPath, $content);
                        $tabularTempFiles[] = [
                            'path' => $tmpPath,
                            'ext' => str_ends_with($lower, '.xlsx') ? 'xlsx' : 'csv',
                            'name' => basename($entryName),
                        ];
                    }
                }
            }
        } finally {
            $zip->close();
        }

        if ($xmlDocuments !== []) {
            return $this->parseFatturaPaDocuments($xmlDocuments, $targetEntities, $tenant);
        }

        if ($tabularTempFiles !== []) {
            try {
                return $this->readTabularFile($tabularTempFiles[0]['path'], $tabularTempFiles[0]['ext'], $targetEntities);
            } finally {
                foreach ($tabularTempFiles as $tmp) {
                    @unlink($tmp['path']);
                }
            }
        }

        throw ValidationException::withMessages(['file' => [__('data_migration.empty_zip_archive')]]);
    }

    /**
     * Parses one or more FatturaPA XML files safely (XXE-protected), deduplicating counterparties and products
     * and emitting staging rows for Customers, Vendors, Products, and HistoricalInvoices.
     *
     * @param  list<array{name: string, xml: string}>  $xmlDocuments
     * @param  list<string>  $targetEntities
     * @return array{
     *     headers: list<string>,
     *     headers_by_entity: array<string, list<string>>,
     *     sample: list<array<string, string>>,
     *     rows: list<array{entity_type: string, row_index: int, source_record_identifier: ?string, raw_payload: array<string, mixed>}>,
     *     metadata: array<string, mixed>
     * }
     */
    private function parseFatturaPaDocuments(array $xmlDocuments, array $targetEntities, ?Tenant $tenant): array
    {
        $tenantVat = $this->normalizeTaxId((string) ($tenant->vat_number ?? ''));
        $tenantCf = $this->normalizeTaxId((string) ($tenant->fiscal_code ?? ''));

        $customers = [];
        $vendors = [];
        $products = [];
        $invoices = [];
        $xmlErrors = [];

        foreach ($xmlDocuments as $docEntry) {
            try {
                $parsed = $this->parseSingleFatturaPaXml($docEntry['xml']);
            } catch (InvalidArgumentException $e) {
                $xmlErrors[] = ['file' => $docEntry['name'], 'error' => $e->getMessage()];

                continue;
            }

            $supplierVat = $this->normalizeTaxId($parsed['supplier']['vat_number'] ?? '');
            $supplierCf = $this->normalizeTaxId($parsed['supplier']['fiscal_code'] ?? '');
            $isActiveInvoice = ($tenantVat !== '' && $supplierVat === $tenantVat)
                || ($tenantCf !== '' && $supplierCf === $tenantCf);

            $counterparty = $isActiveInvoice ? $parsed['recipient'] : $parsed['supplier'];
            $counterpartyKey = $this->normalizeTaxId($counterparty['vat_number'] ?: ($counterparty['fiscal_code'] ?: $counterparty['company_name']));

            if ($isActiveInvoice) {
                if ($counterpartyKey !== '' && ! isset($customers[$counterpartyKey])) {
                    $customers[$counterpartyKey] = $counterparty;
                }
            } else {
                if ($counterpartyKey !== '' && ! isset($vendors[$counterpartyKey])) {
                    $vendors[$counterpartyKey] = $counterparty;
                }
            }

            foreach ($parsed['documents'] as $doc) {
                foreach ($doc['lines'] as $line) {
                    $sku = $line['sku'] !== '' ? $line['sku'] : 'FPA-'.substr(sha1(mb_strtolower($line['description'])), 0, 10);
                    if (! isset($products[$sku])) {
                        $products[$sku] = [
                            'sku' => $sku,
                            'name' => mb_substr($line['description'], 0, 200),
                            'description' => $line['description'],
                            'unit_measure' => $line['unit_of_measure'] ?: 'PZ',
                            'sale_price' => $isActiveInvoice ? $line['unit_price'] : '0.00',
                            'cost_price' => $isActiveInvoice ? '0.00' : $line['unit_price'],
                            'tax_rate' => $line['tax_rate'],
                        ];
                    }
                }

                $invoices[] = [
                    'direction' => $isActiveInvoice ? 'active' : 'passive',
                    'invoice_number' => $doc['number'],
                    'date' => $doc['date'],
                    'due_date' => $doc['due_date'] ?? '',
                    'counterparty_name' => $counterparty['company_name'],
                    'counterparty_vat' => $counterparty['vat_number'],
                    'counterparty_country' => $counterparty['country'],
                    'currency' => $doc['currency'],
                    'total_net' => $doc['total_net'],
                    'total_tax' => $doc['total_tax'],
                    'total_gross' => $doc['total_gross'],
                    'line_description' => $doc['lines'][0]['description'] ?? ('FatturaPA '.$doc['number']),
                    'source_file' => $docEntry['name'],
                ];
            }
        }

        $rows = [];
        $headersByEntity = [];
        $rowIndex = 1;
        $wantAll = $targetEntities === [];

        $appendEntityRows = static function (string $entityType, array $items) use (&$rows, &$headersByEntity, &$rowIndex): void {
            if ($items === []) {
                return;
            }
            $first = reset($items);
            if (is_array($first)) {
                $headersByEntity[$entityType] = array_keys($first);
            }
            foreach ($items as $key => $item) {
                $rows[] = [
                    'entity_type' => $entityType,
                    'row_index' => $rowIndex++,
                    'source_record_identifier' => is_string($key) ? $key : ($item['invoice_number'] ?? (string) $rowIndex),
                    'raw_payload' => $item,
                ];
            }
        };

        if ($wantAll || in_array(MigrationTargetEntity::Customers->value, $targetEntities, true)) {
            $appendEntityRows(MigrationTargetEntity::Customers->value, $customers);
        }
        if ($wantAll || in_array(MigrationTargetEntity::Vendors->value, $targetEntities, true)) {
            $appendEntityRows(MigrationTargetEntity::Vendors->value, $vendors);
        }
        if ($wantAll || in_array(MigrationTargetEntity::Products->value, $targetEntities, true)) {
            $appendEntityRows(MigrationTargetEntity::Products->value, $products);
        }
        if ($wantAll || in_array(MigrationTargetEntity::HistoricalInvoices->value, $targetEntities, true)) {
            $appendEntityRows(MigrationTargetEntity::HistoricalInvoices->value, $invoices);
        }

        $allHeaders = array_values(array_unique(array_merge(...array_values($headersByEntity ?: [[]]))));
        $sample = array_map(
            static fn (array $r): array => array_map(static fn (mixed $v): string => (string) $v, $r['raw_payload']),
            array_slice($rows, 0, 5),
        );

        return [
            'headers' => $allHeaders,
            'headers_by_entity' => $headersByEntity,
            'sample' => $sample,
            'rows' => $rows,
            'metadata' => [
                'format' => 'fatturapa_xml',
                'xml_files_count' => count($xmlDocuments),
                'xml_errors' => $xmlErrors,
                'customers_detected' => count($customers),
                'vendors_detected' => count($vendors),
                'products_detected' => count($products),
                'invoices_detected' => count($invoices),
                'row_count' => count($rows),
            ],
        ];
    }

    /**
     * @return array{
     *     supplier: array<string, string>,
     *     recipient: array<string, string>,
     *     documents: list<array{
     *         number: string,
     *         date: string,
     *         due_date: ?string,
     *         currency: string,
     *         total_net: string,
     *         total_tax: string,
     *         total_gross: string,
     *         lines: list<array{sku: string, description: string, unit_of_measure: string, unit_price: string, tax_rate: string}>
     *     }>
     * }
     */
    private function parseSingleFatturaPaXml(string $xml): array
    {
        SafeXmlLoader::assertSafeXml($xml);

        $clean = (string) preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml);
        $clean = (string) preg_replace('/(<\/?)\w+:/', '$1', $clean);
        $clean = (string) preg_replace('/\s\w+:\w+="[^"]*"/', '', $clean);

        $document = SafeXmlLoader::loadSimpleXml($clean);

        if ($document->getName() !== 'FatturaElettronica') {
            throw new InvalidArgumentException('Invalid FatturaPA root element.');
        }

        $supplierNode = $document->xpath('//FatturaElettronicaHeader/CedentePrestatore')[0] ?? null;
        $recipientNode = $document->xpath('//FatturaElettronicaHeader/CessionarioCommittente')[0] ?? null;
        $bodies = $document->xpath('//FatturaElettronicaBody') ?: [];

        if ($supplierNode === null || $recipientNode === null || $bodies === []) {
            throw new InvalidArgumentException('Missing FatturaPA header or body.');
        }

        $supplier = $this->extractParty($supplierNode);
        $recipient = $this->extractParty($recipientNode);
        $documents = [];

        foreach ($bodies as $body) {
            $number = $this->xmlValue($body, 'DatiGenerali/DatiGeneraliDocumento/Numero') ?? '';
            $date = $this->xmlValue($body, 'DatiGenerali/DatiGeneraliDocumento/Data') ?? '';
            if ($number === '' || $date === '') {
                continue;
            }

            $net = 0.0;
            $tax = 0.0;
            foreach ($body->xpath('DatiBeniServizi/DatiRiepilogo') ?: [] as $summary) {
                $net += (float) ($this->xmlValue($summary, 'ImponibileImporto') ?? 0);
                $tax += (float) ($this->xmlValue($summary, 'Imposta') ?? 0);
            }

            $gross = (float) ($this->xmlValue($body, 'DatiGenerali/DatiGeneraliDocumento/ImportoTotaleDocumento') ?? ($net + $tax));
            $lines = [];

            foreach ($body->xpath('DatiBeniServizi/DettaglioLinee') ?: [] as $line) {
                $lines[] = [
                    'sku' => $this->xmlValue($line, 'CodiceArticolo/CodiceValore') ?? '',
                    'description' => $this->xmlValue($line, 'Descrizione') ?? 'Articolo',
                    'unit_of_measure' => $this->xmlValue($line, 'UnitaMisura') ?? 'PZ',
                    'unit_price' => number_format((float) ($this->xmlValue($line, 'PrezzoUnitario') ?? 0), 2, '.', ''),
                    'tax_rate' => number_format((float) ($this->xmlValue($line, 'AliquotaIVA') ?? 0), 2, '.', ''),
                ];
            }

            $documents[] = [
                'number' => $number,
                'date' => $date,
                'due_date' => $this->xmlValue($body, 'DatiPagamento/DettaglioPagamento/DataScadenzaPagamento'),
                'currency' => $this->xmlValue($body, 'DatiGenerali/DatiGeneraliDocumento/Divisa') ?? 'EUR',
                'total_net' => number_format($net, 2, '.', ''),
                'total_tax' => number_format($tax, 2, '.', ''),
                'total_gross' => number_format($gross, 2, '.', ''),
                'lines' => $lines,
            ];
        }

        return [
            'supplier' => $supplier,
            'recipient' => $recipient,
            'documents' => $documents,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractParty(SimpleXMLElement $party): array
    {
        $name = $this->xmlValue($party, 'DatiAnagrafici/Anagrafica/Denominazione')
            ?? trim(($this->xmlValue($party, 'DatiAnagrafici/Anagrafica/Nome') ?? '').' '.($this->xmlValue($party, 'DatiAnagrafici/Anagrafica/Cognome') ?? ''));

        return [
            'company_name' => $name !== '' ? $name : ($this->xmlValue($party, 'DatiAnagrafici/IdFiscaleIVA/IdCodice') ?? 'Controparte'),
            'country' => strtoupper($this->xmlValue($party, 'DatiAnagrafici/IdFiscaleIVA/IdPaese') ?? ($this->xmlValue($party, 'Sede/Nazione') ?? 'IT')),
            'vat_number' => $this->xmlValue($party, 'DatiAnagrafici/IdFiscaleIVA/IdCodice') ?? '',
            'fiscal_code' => $this->xmlValue($party, 'DatiAnagrafici/CodiceFiscale') ?? '',
            'street' => $this->xmlValue($party, 'Sede/Indirizzo') ?? '',
            'postal_code' => $this->xmlValue($party, 'Sede/CAP') ?? '',
            'city' => $this->xmlValue($party, 'Sede/Comune') ?? '',
            'email' => $this->xmlValue($party, 'Contatti/Email') ?? '',
            'phone' => $this->xmlValue($party, 'Contatti/Telefono') ?? '',
        ];
    }

    private function xmlValue(SimpleXMLElement $node, string $path): ?string
    {
        $found = $node->xpath($path)[0] ?? null;
        $value = $found !== null ? trim((string) $found) : '';

        return $value === '' ? null : $value;
    }

    private function detectCsvDelimiter(string $filePath): string
    {
        $firstLine = (string) strtok((string) file_get_contents($filePath, false, null, 0, 4096), "\n");
        $semicolons = substr_count($firstLine, ';');
        $commas = substr_count($firstLine, ',');
        $tabs = substr_count($firstLine, "\t");

        if ($tabs > $semicolons && $tabs > $commas) {
            return "\t";
        }

        return $semicolons >= $commas ? ';' : ',';
    }

    private function stringifyCell(mixed $cell): string
    {
        return match (true) {
            $cell instanceof DateTimeInterface => $cell->format('Y-m-d'),
            is_bool($cell) => $cell ? '1' : '0',
            default => trim((string) $cell),
        };
    }

    private function normalizeTaxId(string $value): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]/', '', preg_replace('/^IT/i', '', trim($value)) ?? ''));
    }
}
