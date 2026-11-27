<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services\Export;

use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Modules\ForeignVatRefund\Domain\Exceptions\ForeignVatException;
use Modules\ForeignVatRefund\Models\FiscalRepresentativeMandate;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\ForeignVatRefund\Models\VatRefundClaim;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The structured PDF summaries of the paper procedures: ESTV forms 1222 (application) and 1223 (schedule of the invoices by Swiss VAT
 * rate) and the Modello IVA 79 of the Centro Operativo di Pescara (Art. 38-ter DPR 633/72). The official layouts are bound to the
 * forms of the authority; these documents carry every datum the forms ask for, in the same order.
 */
final class ClaimDocumentRenderer
{
    /**
     * @param  Collection<int, ForeignVatLedgerEntry>  $items
     *
     * @throws ForeignVatException vat_rate_not_allowed
     */
    public function estvForm1222(VatRefundClaim $claim, Tenant $tenant, FiscalRepresentativeMandate $mandate, Collection $items): string
    {
        $rows = [
            ['Form', '1222 — Application for refund of Swiss VAT'],
            ['Claim', $claim->claim_number],
            ['Applicant', (string) ($tenant->legal_name ?: $tenant->name)],
            ['Country of establishment', strtoupper($claim->home_country_code)],
            ['VAT / tax number', (string) ($tenant->vat_number ?: $tenant->uid_number)],
            ['Fiscal representative', $mandate->representative_name],
            ['Representative UID', (string) $mandate->representative_tax_id],
            ['Representative address', trim($mandate->address.' '.$mandate->postal_code.' '.$mandate->city)],
            ['Certificate of taxable status valid until', $mandate->certificate_expiry_date?->format('Y-m-d') ?? '-'],
            ['Refund period', $claim->period_start_date->format('Y-m-d').' / '.$claim->period_end_date->format('Y-m-d')],
            ['Currency', $claim->currency_code],
            ['Number of invoices', (string) $items->count()],
            ['Total refund requested', Decimal::round2((string) $claim->total_requested_amount).' '.$claim->currency_code],
            ['Refund to account (IBAN)', (string) $tenant->iban],
            ['Filing deadline (peremptory)', $claim->statutory_deadline_date->format('Y-m-d')],
        ];

        return $this->pdf('Form 1222', $this->definitionTable($rows).$this->ratesSummary($items, $claim->currency_code, true));
    }

    /**
     * @param  Collection<int, ForeignVatLedgerEntry>  $items
     * @param  list<string>  $allowedRates
     */
    public function estvForm1223(VatRefundClaim $claim, Collection $items, array $allowedRates): string
    {
        $body = '';

        foreach ($this->groupByRate($items, $allowedRates) as $rate => $group) {
            $body .= '<h3>'.e('Swiss VAT '.$rate.'%').'</h3>'.$this->invoiceTable($group, $claim->currency_code);
        }

        return $this->pdf('Form 1223 — Schedule of invoices and receipts ('.$claim->claim_number.')', $body);
    }

    /**
     * @param  Collection<int, ForeignVatLedgerEntry>  $items
     */
    public function modelloIva79(VatRefundClaim $claim, Tenant $tenant, Collection $items): string
    {
        $rows = [
            ['Modello', 'IVA 79 — Istanza di rimborso IVA (Art. 38-ter DPR 633/72)'],
            ['Ufficio', 'Agenzia delle Entrate — Centro Operativo di Pescara'],
            ['Richiedente', (string) ($tenant->legal_name ?: $tenant->name)],
            ['Stato di stabilimento', strtoupper($claim->home_country_code)],
            ['Identificativo fiscale', (string) ($tenant->vat_number ?: $tenant->uid_number)],
            ['Reciprocità verificata', 'Sì — '.strtoupper($claim->home_country_code).' / '.strtoupper($claim->target_country_code)],
            ['Periodo di riferimento', $claim->period_start_date->format('Y-m-d').' / '.$claim->period_end_date->format('Y-m-d')],
            ['Numero di documenti', (string) $items->count()],
            ['Rimborso richiesto', Decimal::round2((string) $claim->total_requested_amount).' '.$claim->currency_code],
            ['Accredito su IBAN', (string) $tenant->iban],
            ['Termine di presentazione', $claim->statutory_deadline_date->format('Y-m-d')],
        ];

        return $this->pdf('Modello IVA 79', $this->definitionTable($rows).'<h3>'.e('Elenco documenti').'</h3>'.$this->invoiceTable($items, $claim->currency_code));
    }

    /**
     * @param  Collection<int, ForeignVatLedgerEntry>  $items
     * @param  list<string>  $allowedRates
     * @return array<string, Collection<int, ForeignVatLedgerEntry>>
     */
    public function groupByRate(Collection $items, array $allowedRates): array
    {
        $allowed = array_map(fn (string $rate): string => $this->rate($rate), $allowedRates);
        $groups = [];

        foreach ($items as $item) {
            $rate = $this->rate((string) $item->foreign_vat_rate);

            if ($allowed !== [] && ! in_array($rate, $allowed, true)) {
                throw ForeignVatException::because('vat_rate_not_allowed', ['rate' => $rate, 'document' => $item->document_number]);
            }

            $groups[$rate] ??= new Collection;
            $groups[$rate]->push($item);
        }

        krsort($groups, SORT_NUMERIC);

        return $groups;
    }

    private function rate(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /**
     * @param  Collection<int, ForeignVatLedgerEntry>  $items
     */
    private function ratesSummary(Collection $items, string $currency, bool $swiss): string
    {
        $totals = [];

        foreach ($items as $item) {
            $rate = $this->rate((string) $item->foreign_vat_rate);
            $totals[$rate] = Decimal::add($totals[$rate] ?? '0', (string) $item->recoverable_vat_amount);
        }

        krsort($totals, SORT_NUMERIC);
        $html = '<h3>'.e($swiss ? 'Refund by Swiss VAT rate' : 'Refund by VAT rate').'</h3><table><tr><th>Rate %</th><th class="n">Refund '.e($currency).'</th></tr>';

        foreach ($totals as $rate => $amount) {
            $html .= '<tr><td>'.e((string) $rate).'</td><td class="n">'.e(Decimal::round2($amount)).'</td></tr>';
        }

        return $html.'</table>';
    }

    /**
     * @param  Collection<int, ForeignVatLedgerEntry>  $items
     */
    private function invoiceTable(Collection $items, string $currency): string
    {
        $html = '<table><tr><th>Date</th><th>Document</th><th>Supplier</th><th>Code</th><th class="n">Net</th><th class="n">Rate %</th><th class="n">VAT</th><th class="n">Refund '.e($currency).'</th></tr>';
        $vat = '0';
        $refund = '0';

        foreach ($items as $item) {
            $html .= '<tr><td>'.e($item->document_date->format('Y-m-d')).'</td><td>'.e($item->document_number).'</td><td>'.e((string) $item->supplier_name).'</td><td>'.e((string) $item->expense_category_code).'</td>'
                .'<td class="n">'.e(Decimal::round2((string) $item->net_amount)).'</td><td class="n">'.e($this->rate((string) $item->foreign_vat_rate)).'</td>'
                .'<td class="n">'.e(Decimal::round2((string) $item->foreign_vat_amount)).'</td><td class="n">'.e(Decimal::round2((string) $item->recoverable_vat_amount)).'</td></tr>';
            $vat = Decimal::add($vat, (string) $item->foreign_vat_amount);
            $refund = Decimal::add($refund, (string) $item->recoverable_vat_amount);
        }

        return $html.'<tr class="t"><td colspan="6">Total</td><td class="n">'.e(Decimal::round2($vat)).'</td><td class="n">'.e(Decimal::round2($refund)).'</td></tr></table>';
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows
     */
    private function definitionTable(array $rows): string
    {
        $html = '<table class="d">';

        foreach ($rows as [$label, $value]) {
            $html .= '<tr><th>'.e($label).'</th><td>'.e($value).'</td></tr>';
        }

        return $html.'</table>';
    }

    private function pdf(string $title, string $body): string
    {
        $style = 'body{font-family:DejaVu Sans,sans-serif;font-size:10px}h1{font-size:15px}h3{font-size:12px;margin:14px 0 4px}'
            .'table{width:100%;border-collapse:collapse;margin-bottom:8px}th,td{border:1px solid #999;padding:3px 5px;text-align:left}'
            .'th{background:#eee}.n{text-align:right}.t td{font-weight:bold}table.d th{width:34%}';

        return Pdf::loadHTML('<html><head><meta charset="utf-8"><style>'.$style.'</style></head><body><h1>'.e($title).'</h1>'.$body.'</body></html>')
            ->setPaper('a4', 'landscape')
            ->output();
    }
}
