<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use App\Services\Security\CsvFormulaNeutralizer;
use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Renders a customer statement (see CustomerStatementService) as a PDF or an .xlsx workbook in the requested locale.
 */
final class CustomerStatementExporter
{
    /**
     * @param  array<string, mixed>  $statement
     */
    public function pdf(array $statement, string $locale): string
    {
        return Pdf::loadHTML($this->html($statement, $locale))->setPaper('a4')->output();
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return string the binary content of the workbook
     */
    public function xlsx(array $statement, string $locale): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt');

        if ($path === false) {
            throw new RuntimeException('A temporary file for the statement could not be created.');
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $t = static fn (string $key, array $replace = []): string => __('receivables.statement.'.$key, $replace, $locale);

            $writer->getCurrentSheet()->setName(mb_substr($t('movements'), 0, 31));
            $writer->addRow(Row::fromValues([$t('title'), $statement['customer']['name'], $statement['period']['from'], $statement['period']['to'], $statement['currency']]));
            $writer->addRow(Row::fromValues([$t('opening_balance'), (float) $statement['opening_balance']]));
            $writer->addRow(Row::fromValues([$t('date'), $t('document'), $t('reference'), $t('debit'), $t('credit'), $t('balance')]));

            foreach ($statement['movements'] as $movement) {
                $writer->addRow(Row::fromValues([$movement['date'], CsvFormulaNeutralizer::neutralize($t('kind.'.$movement['kind'])), CsvFormulaNeutralizer::neutralize((string) $movement['reference']), (float) $movement['debit'], (float) $movement['credit'], (float) $movement['balance']]));
            }

            $writer->addRow(Row::fromValues([$t('closing_balance'), (float) $statement['closing_balance']]));

            $writer->addNewSheetAndMakeItCurrent()->setName(mb_substr($t('open_items'), 0, 31));
            $writer->addRow(Row::fromValues([$t('document'), $t('issue_date'), $t('due_date'), $t('original_amount'), $t('open_amount'), $t('days_past_due')]));

            foreach ($statement['open_items'] as $row) {
                $writer->addRow(Row::fromValues([CsvFormulaNeutralizer::neutralize((string) $row['document_number']), $row['issue_date'], $row['due_date'], (float) $row['original_amount'], (float) $row['open_amount'], $row['days_past_due']]));
            }

            $writer->addNewSheetAndMakeItCurrent()->setName(mb_substr($t('aging'), 0, 31));
            $writer->addRow(Row::fromValues([$t('bucket'), $t('open_amount')]));

            foreach ($statement['aging']['totals'] as $key => $amount) {
                $writer->addRow(Row::fromValues([$t('bucket_'.$this->bucketName($key)).' ('.$this->bucketRange($key).')', (float) $amount]));
            }

            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    private function html(array $statement, string $locale): string
    {
        $t = static fn (string $key, array $replace = []): string => e(__('receivables.statement.'.$key, $replace, $locale));
        $money = static fn (string|int|float $amount): string => number_format((float) $amount, 2, '.', "'").' '.e((string) $statement['currency']);

        $rows = '';

        foreach ($statement['movements'] as $movement) {
            $rows .= '<tr><td>'.e($movement['date']).'</td><td>'.e(__('receivables.statement.kind.'.$movement['kind'], [], $locale)).'</td><td>'.e((string) $movement['reference']).'</td><td class="n">'.$money($movement['debit']).'</td><td class="n">'.$money($movement['credit']).'</td><td class="n">'.$money($movement['balance']).'</td></tr>';
        }

        $open = '';

        foreach ($statement['open_items'] as $row) {
            $open .= '<tr'.($row['is_overdue'] ? ' class="late"' : '').'><td>'.e((string) $row['document_number']).'</td><td>'.e($row['due_date']).'</td><td class="n">'.$money($row['open_amount']).'</td><td class="n">'.(int) $row['days_past_due'].'</td></tr>';
        }

        $aging = '';

        foreach ($statement['aging']['totals'] as $key => $amount) {
            $aging .= '<tr><td>'.$t('bucket_'.$this->bucketName($key)).' ('.e($this->bucketRange($key)).')</td><td class="n">'.$money($amount).'</td></tr>';
        }

        return '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px}table{width:100%;border-collapse:collapse;margin-bottom:14px}th,td{border-bottom:1px solid #ccc;padding:3px 4px;text-align:left}.n{text-align:right}.late td{color:#b00020}h1{font-size:16px}h2{font-size:12px;margin:12px 0 4px}</style></head><body>'
            .'<h1>'.$t('title').'</h1>'
            .'<p><strong>'.e((string) $statement['tenant']['name']).'</strong><br>'.e((string) $statement['customer']['name']).'<br>'.$t('period').': '.e($statement['period']['from']).' – '.e($statement['period']['to']).'</p>'
            .'<h2>'.$t('movements').'</h2><table><thead><tr><th>'.$t('date').'</th><th>'.$t('document').'</th><th>'.$t('reference').'</th><th class="n">'.$t('debit').'</th><th class="n">'.$t('credit').'</th><th class="n">'.$t('balance').'</th></tr></thead><tbody>'
            .'<tr><td colspan="5"><strong>'.$t('opening_balance').'</strong></td><td class="n">'.$money($statement['opening_balance']).'</td></tr>'.$rows
            .'<tr><td colspan="5"><strong>'.$t('closing_balance').'</strong></td><td class="n"><strong>'.$money($statement['closing_balance']).'</strong></td></tr></tbody></table>'
            .'<h2>'.$t('open_items').'</h2><table><thead><tr><th>'.$t('document').'</th><th>'.$t('due_date').'</th><th class="n">'.$t('open_amount').'</th><th class="n">'.$t('days_past_due').'</th></tr></thead><tbody>'.$open
            .'<tr><td colspan="2"><strong>'.$t('total_open').'</strong></td><td class="n"><strong>'.$money($statement['total_open']).'</strong></td><td></td></tr>'
            .'<tr><td colspan="2"><strong>'.$t('total_overdue').'</strong></td><td class="n"><strong>'.$money($statement['total_overdue']).'</strong></td><td></td></tr></tbody></table>'
            .'<h2>'.$t('aging').'</h2><table><tbody>'.$aging.'</tbody></table>'
            .'</body></html>';
    }

    /**
     * `current` stays itself; the bounded buckets use one translation each by position of the key.
     */
    private function bucketName(string $key): string
    {
        return match (true) {
            $key === AgingBucketPolicy::Current => 'current',
            str_ends_with($key, '_plus') => 'last',
            default => 'overdue',
        };
    }

    private function bucketRange(string $key): string
    {
        return match (true) {
            $key === AgingBucketPolicy::Current => '0',
            str_ends_with($key, '_plus') => str_replace('_plus', '+', $key),
            default => str_replace('_', '-', $key),
        };
    }
}
