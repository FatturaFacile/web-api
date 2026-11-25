<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Services\Security\CsvFormulaNeutralizer;
use DOMDocument;
use Illuminate\Support\Collection;
use Modules\DataExport\Services\ExcelCsvWriter;
use Modules\GeneralLedger\Models\AccountMapping;
use Modules\GeneralLedger\Models\ChartOfAccount;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Audit-ready Chart of Accounts & Multi-Standard Mapping exporter (Excel .xlsx, CSV .csv, XML .xml).
 */
final readonly class ChartOfAccountsExporter
{
    /** @var list<string> */
    private const array ACCOUNT_COLUMNS = [
        'account_code',
        'account_name',
        'description',
        'account_type',
        'normal_balance',
        'level',
        'hierarchy_path',
        'parent_account_code',
        'is_postable',
        'is_active',
        'is_system',
        'open_items_enabled',
        'vat_code',
        'default_cost_center',
        'allowed_currencies',
        'template_code',
        'statutory_code',
        'mapped_targets',
    ];

    /** @var list<string> */
    private const array MAPPING_COLUMNS = [
        'source_account_code',
        'source_account_name',
        'source_standard',
        'target_standard',
        'target_account_code',
        'target_account_name',
        'mapping_type',
        'is_bidirectional',
        'allocation_percentage',
        'is_active',
        'notes',
    ];

    /**
     * @param  Collection<int, ChartOfAccount>  $accounts
     */
    public function toCsv(Collection $accounts): string
    {
        $rows = [];

        foreach ($accounts as $account) {
            $rows[] = $this->accountRow($account);
        }

        return (new ExcelCsvWriter)->write(self::ACCOUNT_COLUMNS, $rows);
    }

    /**
     * @param  Collection<int, ChartOfAccount>  $accounts
     * @param  Collection<int, AccountMapping>  $mappings
     */
    public function toXlsx(Collection $accounts, Collection $mappings): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'coa_export_');

        if ($tempPath === false) {
            throw new RuntimeException('Unable to allocate temporary file for Chart of Accounts XLSX export.');
        }

        $xlsxPath = $tempPath.'.xlsx';
        @rename($tempPath, $xlsxPath);

        $writer = new Writer;
        $writer->openToFile($xlsxPath);

        try {
            $headerStyle = (new Style)->withFontBold(true);

            // Sheet 1: Chart of Accounts
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Chart of Accounts');
            $sheet->setSheetView(new SheetView(freezeRow: 2));

            $writer->addRow(Row::fromValuesWithStyle(self::ACCOUNT_COLUMNS, $headerStyle));

            foreach ($accounts as $account) {
                $row = $this->accountRow($account);
                $cells = [];

                foreach (self::ACCOUNT_COLUMNS as $index => $column) {
                    $val = $row[$index];
                    if ($column === 'level') {
                        $cells[] = new NumericCell((int) $val);
                    } else {
                        $cells[] = new StringCell((string) CsvFormulaNeutralizer::neutralize((string) $val));
                    }
                }

                $writer->addRow(new Row($cells));
            }

            // Sheet 2: Multi-Standard Account Mappings
            $mappingSheet = $writer->addNewSheetAndMakeItCurrent();
            $mappingSheet->setName('Account Mappings');
            $mappingSheet->setSheetView(new SheetView(freezeRow: 2));

            $writer->addRow(Row::fromValuesWithStyle(self::MAPPING_COLUMNS, $headerStyle));

            foreach ($mappings as $mapping) {
                $srcAcc = $mapping->sourceAccount;
                $writer->addRow(new Row([
                    new StringCell((string) CsvFormulaNeutralizer::neutralize((string) ($srcAcc !== null ? $srcAcc->account_code : ''))),
                    new StringCell((string) CsvFormulaNeutralizer::neutralize((string) ($srcAcc !== null ? $srcAcc->name : ''))),
                    new StringCell((string) CsvFormulaNeutralizer::neutralize($mapping->source_standard)),
                    new StringCell((string) CsvFormulaNeutralizer::neutralize($mapping->target_standard)),
                    new StringCell((string) CsvFormulaNeutralizer::neutralize($mapping->target_account_code)),
                    new StringCell((string) CsvFormulaNeutralizer::neutralize($mapping->target_account_name)),
                    new StringCell((string) CsvFormulaNeutralizer::neutralize($mapping->mapping_type)),
                    new StringCell($mapping->is_bidirectional ? 'true' : 'false'),
                    new NumericCell((float) $mapping->allocation_percentage),
                    new StringCell($mapping->is_active ? 'true' : 'false'),
                    new StringCell((string) CsvFormulaNeutralizer::neutralize((string) ($mapping->notes ?? ''))),
                ]));
            }

            $writer->close();

            $binary = file_get_contents($xlsxPath);

            if ($binary === false) {
                throw new RuntimeException('Unable to read generated Chart of Accounts XLSX export.');
            }

            return $binary;
        } finally {
            @unlink($xlsxPath);
        }
    }

    /**
     * @param  Collection<int, ChartOfAccount>  $accounts
     * @param  Collection<int, AccountMapping>  $mappings
     */
    public function toXml(Collection $accounts, Collection $mappings, string $tenantId, ?string $templateCode = null): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('ChartOfAccountsAuditExport');
        $root->setAttribute('schemaVersion', '1.0');
        $root->setAttribute('generatedAt', now()->toIso8601String());
        $dom->appendChild($root);

        $meta = $dom->createElement('Metadata');
        $meta->appendChild($dom->createElement('TenantId', $this->xmlSafe($tenantId)));
        $meta->appendChild($dom->createElement('TemplateFilter', $this->xmlSafe($templateCode ?? 'all')));
        $meta->appendChild($dom->createElement('TotalAccounts', (string) $accounts->count()));
        $meta->appendChild($dom->createElement('TotalMappings', (string) $mappings->count()));
        $root->appendChild($meta);

        $accountsEl = $dom->createElement('Accounts');
        foreach ($accounts as $account) {
            $accEl = $dom->createElement('Account');
            $accEl->setAttribute('id', (string) $account->id);
            $accEl->appendChild($dom->createElement('Code', $this->xmlSafe($account->account_code)));
            $accEl->appendChild($dom->createElement('Name', $this->xmlSafe($account->name)));
            $accEl->appendChild($dom->createElement('Description', $this->xmlSafe((string) ($account->description ?? ''))));
            $accEl->appendChild($dom->createElement('Type', $this->xmlSafe($account->account_type)));
            $accEl->appendChild($dom->createElement('NormalBalance', $this->xmlSafe($account->normal_balance)));
            $accEl->appendChild($dom->createElement('Level', (string) max(1, (int) $account->level)));
            $accEl->appendChild($dom->createElement('HierarchyPath', $this->xmlSafe($account->hierarchy_path ?? $account->account_code)));
            $accEl->appendChild($dom->createElement('ParentCode', $this->xmlSafe($account->parentAccount !== null ? $account->parentAccount->account_code : '')));
            $accEl->appendChild($dom->createElement('IsPostable', $account->is_postable ? 'true' : 'false'));
            $accEl->appendChild($dom->createElement('IsActive', $account->is_active ? 'true' : 'false'));
            $accEl->appendChild($dom->createElement('IsSystem', $account->is_system ? 'true' : 'false'));
            $accEl->appendChild($dom->createElement('OpenItemsEnabled', $account->open_items_enabled ? 'true' : 'false'));
            $accEl->appendChild($dom->createElement('VatCode', $this->xmlSafe((string) ($account->vatCode !== null ? $account->vatCode->getAttribute('code') : ''))));
            $accEl->appendChild($dom->createElement('DefaultCostCenter', $this->xmlSafe((string) ($account->defaultCostCenter !== null ? $account->defaultCostCenter->getAttribute('code') : ''))));
            $accEl->appendChild($dom->createElement('AllowedCurrencies', $this->xmlSafe(implode(',', $account->allowed_currencies ?? []))));
            $accEl->appendChild($dom->createElement('TemplateCode', $this->xmlSafe((string) ($account->template_code ?? ''))));
            $accEl->appendChild($dom->createElement('StatutoryCode', $this->xmlSafe((string) ($account->statutory_code ?? ''))));
            $accountsEl->appendChild($accEl);
        }
        $root->appendChild($accountsEl);

        $mappingsEl = $dom->createElement('Mappings');
        foreach ($mappings as $mapping) {
            $mapEl = $dom->createElement('Mapping');
            $mapEl->setAttribute('id', (string) $mapping->id);
            $srcAcc = $mapping->sourceAccount;
            $mapEl->appendChild($dom->createElement('SourceCode', $this->xmlSafe($srcAcc !== null ? $srcAcc->account_code : '')));
            $mapEl->appendChild($dom->createElement('SourceStandard', $this->xmlSafe($mapping->source_standard)));
            $mapEl->appendChild($dom->createElement('TargetStandard', $this->xmlSafe($mapping->target_standard)));
            $mapEl->appendChild($dom->createElement('TargetCode', $this->xmlSafe($mapping->target_account_code)));
            $mapEl->appendChild($dom->createElement('TargetName', $this->xmlSafe($mapping->target_account_name)));
            $mapEl->appendChild($dom->createElement('MappingType', $this->xmlSafe($mapping->mapping_type)));
            $mapEl->appendChild($dom->createElement('IsBidirectional', $mapping->is_bidirectional ? 'true' : 'false'));
            $mapEl->appendChild($dom->createElement('AllocationPercentage', (string) $mapping->allocation_percentage));
            $mapEl->appendChild($dom->createElement('IsActive', $mapping->is_active ? 'true' : 'false'));
            $mappingsEl->appendChild($mapEl);
        }
        $root->appendChild($mappingsEl);

        return (string) $dom->saveXML();
    }

    /**
     * @return list<string>
     */
    private function accountRow(ChartOfAccount $account): array
    {
        $targets = $account->outgoingMappings
            ->map(static fn (AccountMapping $m): string => "{$m->target_standard}:{$m->target_account_code} ({$m->allocation_percentage}%)")
            ->implode(' | ');

        return [
            $account->account_code,
            $account->name,
            (string) ($account->description ?? ''),
            $account->account_type,
            $account->normal_balance,
            (string) max(1, (int) $account->level),
            $account->hierarchy_path ?? $account->account_code,
            $account->parentAccount !== null ? $account->parentAccount->account_code : '',
            $account->is_postable ? 'true' : 'false',
            $account->is_active ? 'true' : 'false',
            $account->is_system ? 'true' : 'false',
            $account->open_items_enabled ? 'true' : 'false',
            (string) ($account->vatCode !== null ? $account->vatCode->getAttribute('code') : ''),
            (string) ($account->defaultCostCenter !== null ? $account->defaultCostCenter->getAttribute('code') : ''),
            implode(',', $account->allowed_currencies ?? []),
            (string) ($account->template_code ?? ''),
            (string) ($account->statutory_code ?? ''),
            $targets,
        ];
    }

    private function xmlSafe(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
