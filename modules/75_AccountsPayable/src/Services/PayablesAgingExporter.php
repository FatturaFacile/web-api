<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Services;

use App\Models\Tenant;
use App\Services\Security\CsvFormulaNeutralizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Modules\AccountsPayable\Enums\AgingBucket;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Renders the payables aging report (see PayablesAgingService) as an .xlsx workbook or a PDF, with the headers and the
 * labels of the requested locale.
 *
 * @phpstan-import-type AgingReport from PayablesAgingService
 */
final class PayablesAgingExporter
{
    /**
     * @param  AgingReport  $report
     * @return string the binary content of the workbook
     */
    public function xlsx(array $report, string $locale, ?string $tenantId = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'apag');

        if ($path === false) {
            throw new RuntimeException('A temporary file for the aging report could not be created.');
        }

        try {
            $t = fn (string $key): string => $this->label($key, $locale);
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName(mb_substr($t('summary'), 0, 31));
            $writer->addRow(Row::fromValues([$t('title'), CsvFormulaNeutralizer::neutralize($this->company($tenantId)), $t('as_of'), $report['as_of'], $t('currency'), $report['currency']]));
            $writer->addRow(Row::fromValues(array_merge([$t('supplier'), $t('tax_id')], $this->bucketHeaders($locale), [$t('total_open'), $t('credit_notes'), $t('net_total'), $t('held_total')])));

            foreach ($report['suppliers'] as $supplier) {
                $writer->addRow(Row::fromValues(array_merge(
                    [CsvFormulaNeutralizer::neutralize((string) $supplier['supplier_name']), CsvFormulaNeutralizer::neutralize((string) $supplier['tax_id'])],
                    $this->bucketValues($supplier['buckets']),
                    [(float) $supplier['total_open'], (float) $supplier['credit_notes'], (float) $supplier['net_total'], (float) $supplier['held_total']],
                )));
            }

            $summary = $report['summary'];
            $writer->addRow(Row::fromValues(array_merge(
                [$t('total'), ''],
                $this->bucketValues($summary['buckets']),
                [(float) $summary['total_open'], (float) $summary['credit_notes'], (float) $summary['net_total'], (float) $summary['held_total']],
            )));

            $writer->addNewSheetAndMakeItCurrent()->setName(mb_substr($t('detail'), 0, 31));
            $writer->addRow(Row::fromValues([$t('supplier'), $t('document'), $t('kind'), $t('document_date'), $t('due_date'), $t('days_past_due'), $t('bucket'), $t('amount'), $t('status'), $t('on_hold')]));

            foreach ($report['suppliers'] as $supplier) {
                foreach ($supplier['items'] as $item) {
                    $signed = $item['kind'] === 'credit_note' ? -(float) $item['amount'] : (float) $item['amount'];
                    $writer->addRow(Row::fromValues([
                        CsvFormulaNeutralizer::neutralize((string) $supplier['supplier_name']),
                        CsvFormulaNeutralizer::neutralize((string) $item['document_number']),
                        $t('kind_'.$item['kind']),
                        $item['document_date'],
                        $item['due_date'],
                        $item['days_past_due'],
                        $this->bucketLabel($item['bucket'], $locale),
                        $signed,
                        $t('status_'.$item['status']),
                        $item['on_hold'] ? $t('yes') : $t('no'),
                    ]));
                }
            }

            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  AgingReport  $report
     * @return string the binary content of the PDF
     */
    public function pdf(array $report, string $locale, ?string $tenantId = null): string
    {
        return Pdf::loadHTML($this->html($report, $locale, $tenantId))->setPaper('a4', 'landscape')->output();
    }

    /**
     * @param  AgingReport  $report
     */
    private function html(array $report, string $locale, ?string $tenantId): string
    {
        $t = fn (string $key): string => e($this->label($key, $locale));
        $money = static fn (string|int|float $amount): string => number_format((float) $amount, 2, '.', "'");
        $head = '<th>'.$t('supplier').'</th>';

        foreach (AgingBucket::cases() as $bucket) {
            $head .= '<th class="n">'.e($this->bucketLabel($bucket->value, $locale)).'</th>';
        }

        $head .= '<th class="n">'.$t('total_open').'</th><th class="n">'.$t('credit_notes').'</th><th class="n">'.$t('net_total').'</th>';
        $rows = '';

        foreach ($report['suppliers'] as $supplier) {
            $rows .= '<tr><td>'.e((string) $supplier['supplier_name']).'</td>';

            foreach (AgingBucket::cases() as $bucket) {
                $rows .= '<td class="n">'.$money($supplier['buckets'][$bucket->value]).'</td>';
            }

            $rows .= '<td class="n">'.$money($supplier['total_open']).'</td><td class="n">'.$money($supplier['credit_notes']).'</td><td class="n">'.$money($supplier['net_total']).'</td></tr>';
        }

        $summary = $report['summary'];
        $rows .= '<tr class="total"><td>'.$t('total').'</td>';

        foreach (AgingBucket::cases() as $bucket) {
            $rows .= '<td class="n">'.$money($summary['buckets'][$bucket->value]).'</td>';
        }

        $rows .= '<td class="n">'.$money($summary['total_open']).'</td><td class="n">'.$money($summary['credit_notes']).'</td><td class="n">'.$money($summary['net_total']).'</td></tr>';

        $detail = '';

        foreach ($report['suppliers'] as $supplier) {
            foreach ($supplier['items'] as $item) {
                $signed = $item['kind'] === 'credit_note' ? -(float) $item['amount'] : (float) $item['amount'];
                $detail .= '<tr'.($item['on_hold'] ? ' class="held"' : '').'><td>'.e((string) $supplier['supplier_name']).'</td><td>'.e((string) $item['document_number']).'</td><td>'.e($this->label('kind_'.$item['kind'], $locale)).'</td><td>'.e($item['due_date']).'</td><td class="n">'.(int) $item['days_past_due'].'</td><td>'.e($this->bucketLabel($item['bucket'], $locale)).'</td><td class="n">'.$money($signed).'</td><td>'.e($this->label('status_'.$item['status'], $locale)).'</td></tr>';
            }
        }

        return '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:9px}table{width:100%;border-collapse:collapse;margin-bottom:14px}th,td{border-bottom:1px solid #ccc;padding:3px 4px;text-align:left}.n{text-align:right}.total td{font-weight:bold;border-top:2px solid #333}.held td{color:#b00020}h1{font-size:15px}h2{font-size:11px;margin:12px 0 4px}</style></head><body>'
            .'<h1>'.$t('title').'</h1>'
            .'<p><strong>'.e($this->company($tenantId)).'</strong><br>'.$t('as_of').': '.e($report['as_of']).' · '.$t('currency').': '.e($report['currency']).'</p>'
            .'<h2>'.$t('summary').'</h2><table><thead><tr>'.$head.'</tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<h2>'.$t('detail').'</h2><table><thead><tr><th>'.$t('supplier').'</th><th>'.$t('document').'</th><th>'.$t('kind').'</th><th>'.$t('due_date').'</th><th class="n">'.$t('days_past_due').'</th><th>'.$t('bucket').'</th><th class="n">'.$t('amount').'</th><th>'.$t('status').'</th></tr></thead><tbody>'.$detail.'</tbody></table>'
            .'</body></html>';
    }

    private function label(string $key, string $locale): string
    {
        return __('accounts_payable.aging.'.$key, [], $locale);
    }

    private function bucketLabel(string $bucket, string $locale): string
    {
        return __('accounts_payable.aging.bucket_'.$bucket, [], $locale);
    }

    /**
     * @return list<string>
     */
    private function bucketHeaders(string $locale): array
    {
        return array_map(fn (AgingBucket $bucket): string => $this->bucketLabel($bucket->value, $locale), AgingBucket::cases());
    }

    /**
     * @param  array<string, string>  $buckets
     * @return list<float>
     */
    private function bucketValues(array $buckets): array
    {
        return array_map(static fn (AgingBucket $bucket): float => (float) $buckets[$bucket->value], AgingBucket::cases());
    }

    private function company(?string $tenantId): string
    {
        return $tenantId === null ? '' : (string) (Tenant::query()->whereKey($tenantId)->value('legal_name') ?: Tenant::query()->whereKey($tenantId)->value('name'));
    }
}
