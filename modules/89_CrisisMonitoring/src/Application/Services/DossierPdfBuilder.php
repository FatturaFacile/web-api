<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;

/**
 * Renders the periodic dossier for the board and the statutory auditors on the adequacy of the organisational, administrative and
 * accounting arrangements (Art. 2086 c.c.): executive summary, DSCR over 6 and 12 months with the monthly curve, the seven early-warning
 * indicators, the Art. 25-novies compliance matrix, the CNC practical test, the stress test sensitivity table and the signature block for
 * the minutes of the board and of the board of auditors.
 */
final class DossierPdfBuilder
{
    /**
     * @param  array<string, mixed>  $snapshot  see CrisisEngine::evaluate()
     * @param  list<array{label_key: string, lite: array<string, mixed>}>  $sensitivity
     */
    public function pdf(string $company, array $snapshot, array $sensitivity, string $locale, CarbonImmutable $generatedAt): string
    {
        return Pdf::loadHTML($this->html($company, $snapshot, $sensitivity, $locale, $generatedAt))->setPaper('a4', 'portrait')->output();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array{label_key: string, lite: array<string, mixed>}>  $sensitivity
     */
    public function html(string $company, array $snapshot, array $sensitivity, string $locale, CarbonImmutable $generatedAt): string
    {
        $t = static fn (string $key, array $params = []): string => e(CrisisTexts::get($key, $locale, $params));
        $money = static fn (mixed $amount): string => $amount === null ? '–' : number_format((float) $amount, 2, '.', "'");
        $num = static fn (mixed $value): string => $value === null ? '–' : number_format((float) $value, 2, '.', '');
        $light = static fn (string $status): string => '<span class="s '.e($status).'">'.e(CrisisTexts::get('crisisApi.status.'.$status, $locale)).'</span>';
        $dscr = $snapshot['dscr'];
        $ccii = $snapshot['ccii'];

        $summary = '';

        foreach (['treasury', 'operating'] as $approach) {
            foreach (['m6', 'm12'] as $horizon) {
                $row = $dscr[$approach][$horizon];
                $summary .= '<tr><td>'.$t('crisisApi.dscr.'.$approach).' – '.$t('crisisApi.horizon.'.$horizon).'</td><td class="n">'.$num($row['value']).'</td><td class="n">'.$money($row['numerator']).'</td><td class="n">'.$money($row['denominator']).'</td><td>'.$light($row['status']).'</td></tr>';
            }
        }

        $curve = '';

        foreach ($snapshot['timeline'] as $month) {
            $curve .= '<tr><td>'.e(substr((string) $month['month'], 0, 7)).'</td><td class="n">'.$money($month['operating_inflows']).'</td><td class="n">'.$money($month['operating_outflows']).'</td><td class="n">'.$money($month['debt_service']).'</td><td class="n">'.$money($month['closing_cash']).'</td><td class="n">'.$num($month['dscr_treasury']).'</td><td class="n">'.$num($month['dscr_operating']).'</td></tr>';
        }

        $indicators = '';

        foreach ($ccii['indicators'] as $indicator) {
            $suffix = $indicator['unit'] === 'percent' ? ' %' : '';
            $indicators .= '<tr><td>'.$t($indicator['label_key']).'</td><td class="n">'.($indicator['unit'] === 'amount' ? $money($indicator['actual']) : $num($indicator['actual'])).$suffix.'</td><td class="n">'.($indicator['unit'] === 'amount' ? $money($indicator['threshold']) : $num($indicator['threshold'])).$suffix.'</td><td>'.$t('crisisApi.comparison.'.$indicator['comparison']).'</td><td>'.$light((string) $indicator['status']).'</td></tr>';
        }

        $creditors = '';

        foreach ($ccii['public_creditors'] as $creditor) {
            $creditors .= '<tr><td>'.$t($creditor['label_key']).'</td><td class="n">'.$money($creditor['measured']).'</td><td class="n">'.$money($creditor['limit']).'</td><td class="n">'.(int) $creditor['progress_percent'].' %</td><td>'.$light((string) $creditor['status']).'</td></tr>';
        }

        $cnc = $ccii['cnc'];
        $checks = '';

        foreach ($cnc['checklist'] as $check) {
            $mark = $check['passed'] === null ? '?' : ($check['passed'] ? '✓' : '✗');
            $checks .= '<tr><td>'.$t($check['label_key']).'</td><td class="c">'.$mark.'</td></tr>';
        }

        $stress = '';

        foreach ($sensitivity as $row) {
            $lite = $row['lite'];
            $stress .= '<tr><td>'.$t($row['label_key']).'</td><td class="n">'.$num($lite['dscr']['treasury']['m6']['value']).'</td><td class="n">'.$num($lite['dscr']['treasury']['m12']['value']).'</td><td class="n">'.$num($lite['dscr']['operating']['m12']['value']).'</td><td class="n">'.$money($lite['liquidity']['liquidity_gap']).'</td><td>'.$light((string) $lite['ccii_overall']).'</td></tr>';
        }

        $head = static fn (array $keys): string => '<tr>'.implode('', array_map(static fn (string $key): string => '<th>'.e(CrisisTexts::get($key, $locale)).'</th>', $keys)).'</tr>';
        $triggers = $ccii['primary_triggers'] === [] ? $t('crisisApi.dossier.no_triggers') : implode(', ', array_map(static fn (string $code): string => e(CrisisTexts::get('crisisApi.indicator.'.$code, $locale)), $ccii['primary_triggers']));

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#1f2933}h1{font-size:16px;margin:0 0 4px}h2{font-size:11px;margin:14px 0 4px;border-bottom:1px solid #9aa5b1}'
            .'table{width:100%;border-collapse:collapse;margin-bottom:6px}th,td{border:1px solid #cbd2d9;padding:3px 4px;text-align:left}th{background:#f0f4f8}.n{text-align:right}.c{text-align:center}'
            .'.s{padding:1px 5px;border-radius:3px;color:#fff;font-weight:bold}.green{background:#2e9e4f}.yellow{background:#d49b00}.red{background:#c0392b}.sign td{height:48px;width:50%}'
            .'</style></head><body>'
            .'<h1>'.$t('crisisApi.dossier.title').'</h1>'
            .'<p>'.e($company).' – '.$t('crisisApi.dossier.as_of', ['date' => $snapshot['as_of']]).' – '.$t('crisisApi.dossier.generated', ['timestamp' => $generatedAt->format('Y-m-d H:i')]).'</p>'
            .'<h2>'.$t('crisisApi.dossier.summary').'</h2>'
            .'<p>'.$t('crisisApi.dossier.overall').': '.$light((string) $ccii['overall_status']).' – '.$t('crisisApi.dossier.triggers').': '.$triggers.'</p>'
            .'<table>'.$head(['crisisApi.dossier.col.indicator', 'crisisApi.dossier.col.value', 'crisisApi.dossier.col.numerator', 'crisisApi.dossier.col.denominator', 'crisisApi.dossier.col.status']).$summary.'</table>'
            .'<h2>'.$t('crisisApi.dossier.curve').'</h2>'
            .'<table>'.$head(['crisisApi.dossier.col.month', 'crisisApi.dossier.col.inflows', 'crisisApi.dossier.col.outflows', 'crisisApi.dossier.col.debt_service', 'crisisApi.dossier.col.cash', 'crisisApi.dossier.col.dscr_treasury', 'crisisApi.dossier.col.dscr_operating']).$curve.'</table>'
            .'<h2>'.$t('crisisApi.dossier.indicators').'</h2>'
            .'<table>'.$head(['crisisApi.dossier.col.indicator', 'crisisApi.dossier.col.actual', 'crisisApi.dossier.col.threshold', 'crisisApi.dossier.col.rule', 'crisisApi.dossier.col.status']).$indicators.'</table>'
            .'<h2>'.$t('crisisApi.dossier.creditors').'</h2>'
            .'<table>'.$head(['crisisApi.dossier.col.creditor', 'crisisApi.dossier.col.measured', 'crisisApi.dossier.col.limit', 'crisisApi.dossier.col.progress', 'crisisApi.dossier.col.status']).$creditors.'</table>'
            .'<h2>'.$t('crisisApi.dossier.cnc').'</h2>'
            .'<p>'.$t('crisisApi.dossier.cnc_ratio').': <b>'.$num($cnc['ratio']).'</b> – '.$t($cnc['tier_label_key']).' '.$light((string) $cnc['status']).'</p>'
            .'<p>'.$t('crisisApi.dossier.cnc_debt').': '.$money($cnc['debt_to_restructure']).' – '.$t('crisisApi.dossier.cnc_fcf').': '.$money($cnc['annual_free_cash_flow']).'</p>'
            .'<table>'.$checks.'</table>'
            .'<h2>'.$t('crisisApi.dossier.stress').'</h2>'
            .'<table>'.$head(['crisisApi.dossier.col.scenario', 'crisisApi.dossier.col.dscr_t6', 'crisisApi.dossier.col.dscr_t12', 'crisisApi.dossier.col.dscr_o12', 'crisisApi.dossier.col.gap', 'crisisApi.dossier.col.status']).$stress.'</table>'
            .'<h2>'.$t('crisisApi.dossier.signatures').'</h2>'
            .'<table class="sign"><tr><td>'.$t('crisisApi.dossier.sign_board').'<br>'.$t('crisisApi.dossier.sign_date').': ____ / ____ / ________</td><td>'.$t('crisisApi.dossier.sign_auditors').'<br>'.$t('crisisApi.dossier.sign_date').': ____ / ____ / ________</td></tr></table>'
            .'<p>'.$t('crisisApi.dossier.disclaimer').'</p>'
            .'</body></html>';
    }
}
