<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Modules\CrisisMonitoring\Models\SubordinatedLoanRangruecktritt;

/**
 * Renders the summary of a subordination agreement (Rangrücktrittsvereinbarung, Art. 725b CO) as a two-column PDF: the German text, which
 * is the usual language of the Swiss agreement, next to the text in the language of the reader (English when the reader asks for German).
 */
final class RangruecktrittAgreementBuilder
{
    /** @var list<string> */
    private const array Clauses = ['subordination', 'interest', 'repayment', 'duration', 'insolvency', 'disclosure'];

    /**
     * @param  array{accrued_interest: string, future_interest: string, claim: string}  $claim  the claim at the horizon
     */
    public function pdf(string $company, SubordinatedLoanRangruecktritt $loan, array $claim, string $locale, CarbonImmutable $generatedAt): string
    {
        return Pdf::loadHTML($this->html($company, $loan, $claim, $locale, $generatedAt))->setPaper('a4', 'portrait')->output();
    }

    /**
     * @param  array{accrued_interest: string, future_interest: string, claim: string}  $claim
     */
    public function html(string $company, SubordinatedLoanRangruecktritt $loan, array $claim, string $locale, CarbonImmutable $generatedAt): string
    {
        $second = CrisisTexts::locale($locale) === 'de' ? 'en' : CrisisTexts::locale($locale);
        $money = static fn (string $amount): string => number_format((float) $amount, 2, '.', "'");
        $params = [
            'company' => $company,
            'creditor' => $loan->creditor_name,
            'principal' => $money((string) $loan->principal),
            'rate' => number_format((float) $loan->interest_rate, 2, '.', ''),
            'accrued' => $money($claim['accrued_interest']),
            'future' => $money($claim['future_interest']),
            'claim' => $money($claim['claim']),
            'agreement_date' => CarbonImmutable::parse($loan->agreement_date)->toDateString(),
            'effective_from' => CarbonImmutable::parse($loan->effective_from)->toDateString(),
            'deferral_end' => $loan->deferral_end_date === null ? CrisisTexts::get('crisisApi.agreement.open_ended', $second) : CarbonImmutable::parse($loan->deferral_end_date)->toDateString(),
        ];
        $row = static fn (string $key): string => '<tr><td>'.e(CrisisTexts::get($key, 'de', $params)).'</td><td>'.e(CrisisTexts::get($key, $second, $params)).'</td></tr>';

        $rows = $row('crisisApi.agreement.preamble').$row('crisisApi.agreement.parties');

        foreach (self::Clauses as $clause) {
            $rows .= $row('crisisApi.agreement.clause.'.$clause);
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#1f2933}h1{font-size:15px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #cbd2d9;padding:6px;vertical-align:top;width:50%}th{background:#f0f4f8;text-align:left}.sign td{height:56px}'
            .'</style></head><body>'
            .'<h1>'.e(CrisisTexts::get('crisisApi.agreement.title', 'de')).' / '.e(CrisisTexts::get('crisisApi.agreement.title', $second)).'</h1>'
            .'<p>'.e($company).' – '.e($loan->creditor_name).' – '.e($generatedAt->format('Y-m-d H:i')).'</p>'
            .'<table><tr><th>Deutsch</th><th>'.e(strtoupper($second)).'</th></tr>'.$rows.'</table>'
            .'<table class="sign"><tr><td>'.e(CrisisTexts::get('crisisApi.agreement.sign_company', 'de')).' / '.e(CrisisTexts::get('crisisApi.agreement.sign_company', $second)).'</td><td>'.e(CrisisTexts::get('crisisApi.agreement.sign_creditor', 'de')).' / '.e(CrisisTexts::get('crisisApi.agreement.sign_creditor', $second)).'</td></tr></table>'
            .'<p>'.e(CrisisTexts::get('crisisApi.agreement.disclaimer', 'de')).'</p>'
            .'</body></html>';
    }
}
