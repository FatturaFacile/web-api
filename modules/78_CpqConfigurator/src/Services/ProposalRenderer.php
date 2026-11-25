<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services;

use App\Models\Translation;
use App\Services\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders the dossier of ProposalBuilder as a self-contained HTML page and as a PDF (dompdf). Every label comes from the
 * `PDF.CPQ_PROPOSAL.*` translations of the database (seeded), in the language of the dossier; every value is escaped.
 */
final class ProposalRenderer
{
    private const string Prefix = 'PDF.CPQ_PROPOSAL.';

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $dossier
     */
    public function html(array $dossier): string
    {
        $labels = $this->labels((string) $dossier['document']['locale']);
        $l = static fn (string $key): string => e($labels[$key] ?? self::Prefix.$key);
        $money = static fn (mixed $value): string => e(number_format((float) $value, 2, '.', "'").' '.$dossier['document']['currency']);
        $number = static fn (mixed $value): string => e(ConfigurationHasher::number((float) $value));

        $html = '<html><head><meta charset="utf-8"><style>'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#1f2933}h1{font-size:18px;margin:0 0 4px}h2{font-size:12px;border-bottom:1px solid #9aa5b1;padding-bottom:3px;margin:16px 0 6px}'
            .'table{width:100%;border-collapse:collapse}td,th{padding:3px 5px;text-align:left;border-bottom:1px solid #e4e7eb;vertical-align:top}th{background:#f0f4f8}.r{text-align:right}.muted{color:#627d98}.chip{background:#e6f6ff;padding:1px 4px}'
            .'</style></head><body>';

        $product = $dossier['product'];
        $texts = $dossier['texts'];
        $html .= '<h1>'.e($texts['title'] ?? $labels['TITLE'] ?? '').'</h1>';
        $html .= '<div class="muted">'.$l('REFERENCE').': '.e((string) ($dossier['document']['reference'] ?? '-')).' · '.e($product['model_name']).' ('.e($product['model_code']).')';
        $html .= $dossier['document']['customer'] !== null ? ' · '.$l('CUSTOMER').': '.e((string) $dossier['document']['customer']) : '';
        $html .= '</div>';

        if (($texts['intro'] ?? null) !== null) {
            $html .= '<p>'.e($texts['intro']).'</p>';
        }

        $html .= '<h2>'.$l('SPECIFICATION').'</h2>';

        foreach ($dossier['specification'] as $group) {
            $html .= '<table>'.($group['step'] !== null ? '<tr><th colspan="2">'.e($group['step']).'</th></tr>' : '');

            foreach ($group['rows'] as $row) {
                $html .= '<tr><td>'.e((string) $row['label']).($row['auto_included'] ? ' <span class="chip">'.$l('AUTO_INCLUDED').'</span>' : '').'</td><td class="r">'.e((string) $row['value']).($row['unit'] ? ' '.e((string) $row['unit']) : '').'</td></tr>';
            }

            $html .= '</table>';
        }

        if ($dossier['auto_included'] !== []) {
            $html .= '<h2>'.$l('AUTO_INCLUDED_TITLE').'</h2><table>';

            foreach ($dossier['auto_included'] as $entry) {
                $html .= '<tr><td>'.e((string) $entry['characteristic_label']).': '.e((string) $entry['value_label']).'</td><td>'.e((string) $entry['message']).'</td></tr>';
            }

            $html .= '</table>';
        }

        if ($dossier['bom'] !== []) {
            $html .= '<h2>'.$l('SCOPE_OF_SUPPLY').'</h2><table><tr><th>'.$l('CODE').'</th><th>'.$l('DESCRIPTION').'</th><th class="r">'.$l('QUANTITY').'</th></tr>';

            foreach ($dossier['bom'] as $line) {
                $html .= '<tr><td>'.e((string) $line['item_code']).'</td><td>'.e((string) $line['name']).'</td><td class="r">'.$number($line['quantity']).' '.e((string) $line['uom']).'</td></tr>';
            }

            $html .= '</table>';
        }

        if ($dossier['dimensional_notes'] !== [] || ($texts['installation'] ?? null) !== null) {
            $html .= '<h2>'.$l('DIMENSIONS_INSTALLATION').'</h2><ul>';

            foreach ($dossier['dimensional_notes'] as $note) {
                $html .= '<li>'.e($note).'</li>';
            }

            $html .= '</ul>'.(($texts['installation'] ?? null) !== null ? '<p>'.e($texts['installation']).'</p>' : '');
        }

        $commercial = $dossier['commercial'];
        $html .= '<h2>'.$l('COMMERCIAL').'</h2><table>'
            .'<tr><td>'.$l('LIST_PRICE').'</td><td class="r">'.$money($commercial['list_price']).'</td></tr>'
            .((float) $commercial['discount_percent'] > 0 ? '<tr><td>'.$l('DISCOUNT').'</td><td class="r">'.$number($commercial['discount_percent']).' %</td></tr>' : '')
            .'<tr><td>'.$l('UNIT_PRICE').'</td><td class="r">'.$money($commercial['unit_price']).'</td></tr>'
            .'<tr><td>'.$l('QUANTITY').'</td><td class="r">'.$number($commercial['quantity']).'</td></tr>'
            .'<tr><th>'.$l('TOTAL').'</th><th class="r">'.$money($commercial['total_price']).'</th></tr>'
            .'<tr><td>'.$l('LEAD_TIME').'</td><td class="r">'.e((string) $commercial['lead_time_days']).' '.$l('DAYS').'</td></tr>'
            .'<tr><td>'.$l('VALIDITY').'</td><td class="r">'.e((string) $dossier['document']['valid_days']).' '.$l('DAYS').'</td></tr></table>';

        if ($dossier['breakdown'] !== null) {
            $breakdown = $dossier['breakdown'];
            $html .= '<h2>'.$l('INDUSTRIAL_BREAKDOWN').'</h2><table>'
                .'<tr><td>'.$l('BOM_COST').'</td><td class="r">'.$money($breakdown['bom_cost']).'</td></tr>'
                .'<tr><td>'.$l('ROUTING_COST').'</td><td class="r">'.$money($breakdown['routing_cost']).'</td></tr>'
                .'<tr><td>'.$l('INDUSTRIAL_COST').'</td><td class="r">'.$money($breakdown['industrial_cost']).'</td></tr>'
                .'<tr><td>'.$l('MARGIN').'</td><td class="r">'.$money($breakdown['margin_amount']).' ('.$number($breakdown['margin_percent']).' %)</td></tr></table>';
        }

        foreach (['delivery' => 'DELIVERY', 'warranty' => 'WARRANTY', 'terms' => 'TERMS'] as $key => $label) {
            if (($texts[$key] ?? null) !== null) {
                $html .= '<h2>'.$l($label).'</h2><p>'.e($texts[$key]).'</p>';
            }
        }

        return $html.'<p class="muted">'.$l('HASH').': '.e((string) ($product['hash'] ?? '')).'</p></body></html>';
    }

    /**
     * @param  array<string, mixed>  $dossier
     */
    public function pdf(array $dossier): string
    {
        return Pdf::loadHTML($this->html($dossier))->setPaper('a4')->output();
    }

    /**
     * @return array<string, string> label key (without prefix) => text
     */
    private function labels(string $locale): array
    {
        $tenantId = $this->tenants->getTenantId();
        $rows = Translation::query()
            ->where('locale', substr($locale, 0, 2))
            ->where('key', 'like', self::Prefix.'%')
            ->where(fn ($query) => $query->whereNull('tenant_id')->when($tenantId !== null, fn ($inner) => $inner->orWhere('tenant_id', $tenantId)))
            ->orderByRaw('tenant_id is not null')
            ->get(['key', 'value']);

        $labels = [];

        foreach ($rows as $row) {
            $labels[substr((string) $row->key, strlen(self::Prefix))] = (string) $row->value;
        }

        return $labels;
    }
}
