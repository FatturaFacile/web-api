<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance;

use App\Models\Privacy\ProcessingActivityRecordRopa;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Exports the record of processing activities for inspections of the Italian Garante and the Swiss FDPIC/IFPDT/EDÖB.
 */
final class RopaExportService
{
    /** @var list<string> */
    private const array Headers = ['Code', 'Activity', 'Purpose', 'Legal basis', 'Data subjects', 'Personal data', 'Recipients', 'Cross-border transfers', 'Measures (TOMs)', 'Retention (months)', 'Retention legal reference', 'Active'];

    /**
     * @param  Collection<int, ProcessingActivityRecordRopa>  $records
     */
    public function pdf(Collection $records, string $companyName): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 9px">';
        $html .= '<h1>Record of processing activities — '.$e($companyName).'</h1>';
        $html .= '<p>Art. 30 GDPR (Garante per la protezione dei dati personali) · Art. 12 nLPD (FDPIC / IFPDT / EDÖB) · '.$e(now()->toDateString()).'</p>';
        $html .= '<table border="1" cellspacing="0" cellpadding="3" width="100%"><tr>';
        foreach (self::Headers as $header) {
            $html .= '<th>'.$e($header).'</th>';
        }

        $html .= '</tr>';
        foreach ($records as $record) {
            $html .= '<tr>';
            foreach ($this->row($record) as $cell) {
                $html .= '<td>'.$e($cell).'</td>';
            }

            $html .= '</tr>';
        }

        return Pdf::loadHTML($html.'</table></body></html>')->setPaper('a4', 'landscape')->output();
    }

    /**
     * @param  Collection<int, ProcessingActivityRecordRopa>  $records
     */
    public function xlsx(Collection $records): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ropa');
        if ($path === false) {
            throw new RuntimeException('Cannot create the export file.');
        }

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::Headers));
        foreach ($records as $record) {
            $writer->addRow(Row::fromValues(array_map($this->safe(...), $this->row($record))));
        }

        $writer->close();
        $binary = (string) file_get_contents($path);
        @unlink($path);

        return $binary;
    }

    /**
     * @return list<string>
     */
    private function row(ProcessingActivityRecordRopa $r): array
    {
        $join = static fn (?array $v): string => implode('; ', array_map(static fn (mixed $i): string => is_array($i) ? trim(($i['country_code'] ?? '').' '.($i['safeguard_mechanism'] ?? '')) : (string) $i, $v ?? []));

        return [
            $r->activity_code, $r->activity_name, $r->purpose, $r->legal_basis->value,
            $join($r->data_subject_categories), $join($r->personal_data_categories), $join($r->recipient_categories),
            $join($r->cross_border_transfers), $join($r->technical_organizational_measures),
            (string) $r->retention_period_months, (string) $r->retention_legal_reference, $r->is_active ? 'yes' : 'no',
        ];
    }

    /** Neutralises spreadsheet formula injection. */
    private function safe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
