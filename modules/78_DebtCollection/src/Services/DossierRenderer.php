<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\DebtCollection\Models\Dossier;

/**
 * Renders the Betreibungsbegehren (request for debt enforcement, LEF art. 67) of a dossier as a PDF: creditor, debtor,
 * competent office, the claim with the reason of every debt and the default interest, and the chronology of invoices,
 * payments and dunning notices. Captions come from the `betreibung` template of the language of the dossier.
 */
final class DossierRenderer
{
    public function __construct(private readonly TemplateService $templates) {}

    public function pdf(Dossier $dossier): string
    {
        return Pdf::loadHTML($this->html($dossier))->setPaper('a4')->output();
    }

    public function html(Dossier $dossier): string
    {
        $labels = $this->templates->labels((string) $dossier->locale, 'betreibung');
        $t = static fn (string $key): string => e($labels[$key] ?? '');
        $money = static fn (mixed $amount): string => number_format((float) $amount, 2, '.', "'");
        $date = static fn (string $iso): string => date('d.m.Y', (int) strtotime($iso));
        $snapshot = (array) $dossier->snapshot;
        $currency = e((string) $dossier->currency);
        $party = static fn (array $data): string => e((string) ($data['name'] ?? '')).'<br>'.e(trim((string) ($data['street'] ?? ''))).'<br>'.e(trim((string) ($data['postal_code'] ?? '').' '.(string) ($data['city'] ?? ''))).(! empty($data['uid']) ? '<br>UID: '.e((string) $data['uid']) : '');
        $office = (array) ($dossier->office_snapshot ?? []);

        $claims = '';
        $chronology = [];

        foreach ((array) ($snapshot['claims'] ?? []) as $index => $claim) {
            $claims .= '<tr><td>'.($index + 1).'</td><td>'.e((string) $claim['document_number']).' ('.$date((string) $claim['issue_date']).')</td><td>'.$date((string) $claim['due_date']).'</td>'
                .'<td class="n">'.$currency.' '.$money($claim['principal']).'</td><td class="n">'.e((string) rtrim(rtrim((string) $claim['rate_percent'], '0'), '.')).'%</td><td class="n">'.$currency.' '.$money($claim['interest']).'</td></tr>';
            $chronology[] = [(string) $claim['issue_date'], $t('event_invoice').' '.e((string) $claim['document_number']).' '.$currency.' '.$money($claim['original_amount'])];
            $chronology[] = [(string) $claim['due_date'], $t('event_due').' '.e((string) $claim['document_number'])];

            foreach ((array) $claim['settlements'] as $settlement) {
                $chronology[] = [(string) $settlement['value_date'], $t('event_settlement').' ('.e((string) $settlement['type']).') '.$currency.' '.$money($settlement['amount'])];
            }
        }

        foreach ((array) ($snapshot['notices'] ?? []) as $notice) {
            $chronology[] = [(string) $notice['issued_on'], $t('event_notice').' '.e((string) $notice['number']).' - '.$t('level').' '.(int) $notice['level'].' ('.e((string) $notice['channel']).')'];
        }

        usort($chronology, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $events = '';

        foreach ($chronology as [$day, $text]) {
            $events .= '<tr><td>'.$date($day).'</td><td>'.$text.'</td></tr>';
        }

        return '<html><head><meta charset="utf-8"><style>'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:9.5pt;color:#222}h1{font-size:15pt;margin:0 0 4px}h2{font-size:11pt;margin:16px 0 4px;border-bottom:1px solid #999}'
            .'table{width:100%;border-collapse:collapse;margin:6px 0}th,td{border-bottom:1px solid #ddd;padding:3px 5px;text-align:left;vertical-align:top}th{background:#f2f2f2}.n{text-align:right}.total td{font-weight:bold;border-top:2px solid #222}'
            .'.cols td{border:none;width:50%}</style></head><body>'
            .'<h1>'.$t('title').'</h1><div>'.$t('number').': '.e((string) $dossier->number).' &middot; '.$t('date').': '.$date((string) ($snapshot['as_of'] ?? now()->toDateString())).'</div>'
            .'<h2>'.$t('office').'</h2><div>'.($office === [] ? '-' : e((string) ($office['name'] ?? '')).'<br>'.e((string) ($office['address'] ?? '')).'<br>'.e(trim((string) ($office['postal_code'] ?? '').' '.(string) ($office['city'] ?? '')))).'</div>'
            .'<table class="cols"><tr><td><h2>'.$t('creditor').'</h2>'.$party((array) ($snapshot['creditor'] ?? [])).'</td><td><h2>'.$t('debtor').'</h2>'.$party((array) ($snapshot['debtor'] ?? [])).'</td></tr></table>'
            .'<h2>'.$t('claim').'</h2>'
            .'<table><thead><tr><th>#</th><th>'.$t('claim_reason').'</th><th>'.$t('due_date').'</th><th class="n">'.$t('principal').'</th><th class="n">'.$t('interest_rate').'</th><th class="n">'.$t('interest').'</th></tr></thead><tbody>'.$claims.'</tbody></table>'
            .'<table><tbody>'
            .'<tr><td>'.$t('total_principal').'</td><td class="n">'.$currency.' '.$money($dossier->principal).'</td></tr>'
            .'<tr><td>'.$t('total_interest').'</td><td class="n">'.$currency.' '.$money($dossier->interest).'</td></tr>'
            .'<tr><td>'.$t('total_costs').'</td><td class="n">'.$currency.' '.$money($dossier->fee).'</td></tr>'
            .'<tr class="total"><td>'.$t('total_claim').'</td><td class="n">'.$currency.' '.$money($dossier->total).'</td></tr></tbody></table>'
            .'<p>'.$t('interest_note').'</p>'
            .'<h2>'.$t('chronology').'</h2><table><tbody>'.$events.'</tbody></table>'
            .'<h2>'.$t('attachments').'</h2><p>'.$t('attachments_text').'</p>'
            .'</body></html>';
    }
}
