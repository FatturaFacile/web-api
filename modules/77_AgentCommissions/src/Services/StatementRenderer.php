<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\Statement;

/**
 * Renders a commission statement as a PDF in the locale of the recipient. Labels come from the backend dictionary
 * (lang/<locale>/agent_commissions.php), like every other generated document.
 */
final class StatementRenderer
{
    public function pdf(Statement $statement, string $locale): string
    {
        return Pdf::loadHTML($this->html($statement, $locale))->setPaper('a4')->output();
    }

    private function html(Statement $statement, string $locale): string
    {
        $t = static fn (string $key, array $replace = []): string => e(__('agent_commissions.statement.'.$key, $replace, $locale));
        $money = static fn (mixed $amount): string => number_format((float) $amount, 2, '.', "'");
        $profile = AgentProfile::query()->with('representative')->find($statement->agent_profile_id);
        $statement->loadMissing('lines');

        $rows = '';

        foreach ($statement->lines as $line) {
            $rows .= '<tr><td>'.e((string) $line->invoice_number).'</td><td>'.e((string) $line->invoice_date?->format('Y-m-d')).'</td><td>'.e((string) $line->customer_name).'</td>'
                .'<td>'.$t('basis_'.$line->accrual_basis).'</td><td class="n">'.$money($line->base_amount).'</td><td class="n">'.($line->rate === null ? '' : $money($line->rate)).'</td>'
                .'<td class="n">'.$money($line->commission_amount).'</td></tr>';
        }

        $summary = [
            'own_commission' => $statement->own_commission_total,
            'override_commission' => $statement->override_commission_total,
            'clawback' => $statement->clawback_total,
            'gross_commission' => $statement->gross_commission,
            'vat' => $statement->vat_amount,
            'enasarco_base' => $statement->enasarco_base,
            'enasarco_agent' => $statement->enasarco_agent,
            'enasarco_company' => $statement->enasarco_company,
            'firr' => $statement->firr_accrual,
            'withholding_base' => $statement->withholding_base,
            'withholding' => $statement->withholding_amount,
            'net_payable' => $statement->net_payable,
            'blocked' => $statement->blocked_amount,
        ];
        $summaryRows = '';

        foreach ($summary as $key => $amount) {
            $strong = in_array($key, ['gross_commission', 'net_payable'], true);
            $open = $strong ? '<strong>' : '';
            $close = $strong ? '</strong>' : '';
            $summaryRows .= '<tr><td>'.$open.$t($key).$close.'</td><td class="n">'.$open.$money($amount).' '.e((string) $statement->currency).$close.'</td></tr>';
        }

        return '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px}table{width:100%;border-collapse:collapse;margin-bottom:14px}th,td{border-bottom:1px solid #ccc;padding:3px 4px;text-align:left}.n{text-align:right}h1{font-size:16px}h2{font-size:12px;margin:12px 0 4px}.note{color:#b45309}</style></head><body>'
            .'<h1>'.$t('title').' '.e((string) $statement->number).'</h1>'
            .'<p><strong>'.$t('agent').':</strong> '.e((string) $profile?->representative?->name).'<br><strong>'.$t('period').':</strong> '.e($statement->period_start->format('Y-m-d')).' – '.e($statement->period_end->format('Y-m-d')).'</p>'
            .'<table><thead><tr><th>'.$t('invoice').'</th><th>'.$t('date').'</th><th>'.$t('customer').'</th><th>'.$t('basis').'</th><th class="n">'.$t('base').'</th><th class="n">'.$t('rate').'</th><th class="n">'.$t('commission').'</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<h2>'.$t('summary').'</h2><table><tbody>'.$summaryRows.'</tbody></table>'
            .($statement->massimale_reached ? '<p class="note">'.$t('massimale_reached').'</p>' : '')
            .'</body></html>';
    }
}
