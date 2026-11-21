<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Services;

use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Hse\Domain\Enums\Jurisdiction;
use Modules\Hse\Infrastructure\Models\PpeDistributionRecord;
use Modules\Hse\Infrastructure\Support\VerbaleLabels;
use Modules\HumanResources\Models\Employee;

/**
 * Renders the Verbale di Consegna DPI / PSA Übergabeprotokoll in the locale of the hand-over: employer and worker,
 * the PPE with its CE standard, lot, replacement date, the legal acknowledgement, the signature and its integrity hash.
 */
final class VerbaleConsegnaPdfGenerator
{
    /**
     * @return string the PDF binary
     */
    public function render(PpeDistributionRecord $record, Tenant $tenant, Employee $employee, string $productName): string
    {
        $labels = VerbaleLabels::for($record->locale, Jurisdiction::forCountry($tenant->country));

        $signature = (string) $record->signature_image_base64_or_ref;
        $isImage = str_starts_with($signature, 'data:image/') || preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $signature) === 1;
        $signatureHtml = $isImage
            ? '<img src="'.e(str_starts_with($signature, 'data:image/') ? $signature : 'data:image/png;base64,'.$signature).'" style="max-height:90px">'
            : '<em>'.e($signature).'</em>';

        $rows = [
            $labels['item'] => $productName,
            $labels['standard'] => (string) $record->ce_marking_standard,
            $labels['lot'] => (string) $record->lot_or_serial_id,
            $labels['quantity'] => rtrim(rtrim((string) $record->quantity, '0'), '.'),
            $labels['handed_over'] => $record->handed_over_at->format('d.m.Y H:i'),
            $labels['replacement'] => $record->replacement_due_at?->format('d.m.Y') ?? '-',
        ];

        $table = '';

        foreach ($rows as $label => $value) {
            $table .= '<tr><th>'.e($label).'</th><td>'.e($value).'</td></tr>';
        }

        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px}table{width:100%;border-collapse:collapse;margin:10px 0}th,td{border:1px solid #999;padding:5px;text-align:left}th{width:32%;background:#eee}.hash{font-family:monospace;font-size:9px}</style></head><body>'
            .'<h2>'.e($labels['title']).'</h2>'
            .'<p><strong>'.e($labels['employer']).':</strong> '.e((string) ($tenant->legal_name ?? $tenant->name)).' — '.e($labels['vat']).' '.e((string) $tenant->vat_number).'</p>'
            .'<p><strong>'.e($labels['worker']).':</strong> '.e(trim($employee->first_name.' '.$employee->last_name)).'</p>'
            .'<table>'.$table.'</table>'
            .'<p>'.e($labels['legal']).'</p>'
            .'<p><strong>'.e($labels['signature']).':</strong><br>'.$signatureHtml.'</p>'
            .'<p class="hash">'.e($labels['hash']).': '.e($record->employee_signature_hash).'</p>'
            .'</body></html>';

        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }
}
