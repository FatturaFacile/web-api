<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use App\Models\Contact;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Modules\DebtCollection\Models\Notice;

/**
 * Renders a dunning notice or messa in mora as a PDF in the language of the notice. The text comes from the notice
 * itself (rendered from its template when it was created) and the captions from the `labels` template of the language.
 */
final class NoticeRenderer
{
    public function __construct(private readonly TemplateService $templates) {}

    public function pdf(Notice $notice): string
    {
        return Pdf::loadHTML($this->html($notice))->setPaper('a4')->output();
    }

    public function html(Notice $notice): string
    {
        $notice->loadMissing('lines');
        $labels = $this->templates->labels((string) $notice->locale);
        $t = static fn (string $key): string => e($labels[$key] ?? '');
        $money = static fn (mixed $amount): string => number_format((float) $amount, 2, '.', "'");
        $customer = Contact::query()->find($notice->customer_id);
        $tenant = Tenant::query()->find($notice->tenant_id);
        $debtor = $customer === null ? '' : e((string) ($customer->company_name ?: trim($customer->first_name.' '.$customer->last_name)));

        $rows = '';

        foreach ($notice->lines as $line) {
            $rows .= '<tr><td>'.e((string) $line->document_number).'</td><td>'.$line->due_date->format('d.m.Y').'</td><td class="n">'.$money($line->principal).'</td>'
                .'<td class="n">'.(int) $line->interest_days.'</td><td class="n">'.$money($line->interest).'</td></tr>';
        }

        $body = nl2br(e((string) $notice->body));

        return '<html><head><meta charset="utf-8"><style>'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:10pt;color:#222}h1{font-size:14pt;margin:18px 0 6px}table{width:100%;border-collapse:collapse;margin:12px 0}'
            .'th,td{border-bottom:1px solid #ccc;padding:4px 6px;text-align:left}th{background:#f2f2f2}.n{text-align:right}.meta{color:#555}.total td{font-weight:bold;border-top:2px solid #222}'
            .'</style></head><body>'
            .'<div class="meta"><strong>'.e((string) ($tenant?->legal_name ?: $tenant?->name)).'</strong></div>'
            .'<p>'.$t('to').': <strong>'.$debtor.'</strong></p>'
            .'<p class="meta">'.$t('notice_number').': '.e((string) $notice->number).' &middot; '.$t('date').': '.$notice->issued_on->format('d.m.Y').'</p>'
            .'<h1>'.e((string) $notice->subject).'</h1><p>'.$body.'</p>'
            .'<table><thead><tr><th>'.$t('document').'</th><th>'.$t('due_date').'</th><th class="n">'.$t('principal').'</th><th class="n">'.$t('days').'</th><th class="n">'.$t('interest').'</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<table><tbody>'
            .'<tr><td>'.$t('total_principal').'</td><td class="n">'.e((string) $notice->currency).' '.$money($notice->principal).'</td></tr>'
            .'<tr><td>'.$t('total_interest').'</td><td class="n">'.e((string) $notice->currency).' '.$money($notice->interest).'</td></tr>'
            .'<tr><td>'.$t('total_fee').'</td><td class="n">'.e((string) $notice->currency).' '.$money($notice->fee).'</td></tr>'
            .'<tr class="total"><td>'.$t('total_due').'</td><td class="n">'.e((string) $notice->currency).' '.$money($notice->total).'</td></tr>'
            .'</tbody></table>'
            .'<p>'.$t('pay_by').': <strong>'.($notice->new_due_date?->format('d.m.Y') ?? '').'</strong></p>'
            .'</body></html>';
    }
}
