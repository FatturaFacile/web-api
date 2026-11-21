<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\ValueObjects\SuggestionProposal;
use Modules\AiCognitive\Infrastructure\Models\AiSuggestion;

/**
 * The compliance and anomaly guard. The checks are deterministic rules, so every flag can be explained and audited without a
 * model: duplicate invoices, a supplier IBAN that differs from the master data (business email compromise), VAT rates that
 * are not statutory for the jurisdiction, and the statutory fields missing before a Swiss QR-bill or an Italian SDI XML is
 * produced. Each finding is a suggestion of kind `anomaly` in the review queue; IBANs are shown only by their last digits.
 */
final readonly class AnomalyGuardService
{
    public function __construct(private SuggestionService $suggestions) {}

    /**
     * Scans the invoices of the last days (issued and received) of a tenant.
     *
     * @return list<AiSuggestion>
     */
    public function scanTenant(string $tenantId, int $days = 30): array
    {
        $since = now()->subDays($days)->toDateString();
        $found = [];

        foreach (DB::table('passive_invoices')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('date', '>=', $since)->pluck('id') as $id) {
            array_push($found, ...$this->scanPassiveInvoice($tenantId, (string) $id));
        }

        foreach (DB::table('invoices')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('date', '>=', $since)->pluck('id') as $id) {
            array_push($found, ...$this->scanSalesInvoice($tenantId, (string) $id));
        }

        return $found;
    }

    /**
     * @return list<AiSuggestion>
     */
    public function scanPassiveInvoice(string $tenantId, string $invoiceId): array
    {
        $invoice = DB::table('passive_invoices')->where('tenant_id', $tenantId)->where('id', $invoiceId)->first();

        if ($invoice === null) {
            return [];
        }

        $findings = [];
        $window = (int) config('ai_cognitive.anomaly.duplicate_window_days', 365);
        $number = $this->normalizeNumber((string) $invoice->invoice_number);

        $duplicates = DB::table('passive_invoices')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('id', '!=', $invoiceId)
            ->where('supplier_id', $invoice->supplier_id)
            ->where('date', '>=', now()->subDays($window)->toDateString())
            ->get(['id', 'invoice_number', 'date', 'total_amount']);

        foreach ($duplicates as $other) {
            $sameNumber = $this->normalizeNumber((string) $other->invoice_number) === $number;
            $sameAmountClose = abs((float) $other->total_amount - (float) $invoice->total_amount) <= (float) config('ai_cognitive.anomaly.amount_tolerance', 0.01)
                && abs((strtotime((string) $other->date) - strtotime((string) $invoice->date)) / 86400) <= 7;

            if ($sameNumber || $sameAmountClose) {
                $findings[] = ['duplicate_invoice', 'high', $invoiceId, 'passive_invoice', ['duplicate_of' => $other->id, 'same_number' => $sameNumber, 'same_amount_within_7_days' => $sameAmountClose], 0.9, 'Another invoice of the same supplier has the same number or the same amount within a week.'];

                break;
            }
        }

        $incoming = $this->incomingIban($invoice);
        $master = DB::table('contacts')->where('tenant_id', $tenantId)->where('id', $invoice->supplier_id)->value('iban');

        if ($incoming !== null && is_string($master) && $master !== '' && $this->normalizeIban($master) !== $incoming) {
            $findings[] = ['iban_change', 'critical', $invoiceId, 'passive_invoice', ['invoice_iban' => $this->mask($incoming), 'master_iban' => $this->mask($this->normalizeIban($master))], 0.95, 'The payment IBAN on the invoice differs from the IBAN of the supplier master data: call the supplier on a known number before paying (business email compromise).'];
        }

        return $this->emit($tenantId, $findings);
    }

    /**
     * @return list<AiSuggestion>
     */
    public function scanSalesInvoice(string $tenantId, string $invoiceId): array
    {
        $invoice = DB::table('invoices')->where('tenant_id', $tenantId)->where('id', $invoiceId)->first();

        if ($invoice === null) {
            return [];
        }

        $findings = [];
        $jurisdiction = strtoupper((string) $invoice->jurisdiction);
        $rates = AccountingCopilotService::vatRates($jurisdiction);

        if (! (bool) $invoice->reverse_charge) {
            $bad = [];

            foreach (DB::table('invoice_items')->where('tenant_id', $tenantId)->where('invoice_id', $invoiceId)->whereNull('deleted_at')->get(['id', 'description', 'tax_rate', 'natura']) as $item) {
                if (! $this->isStatutoryRate((float) $item->tax_rate, $rates) && ($item->natura ?? '') === '') {
                    $bad[] = ['item_id' => $item->id, 'tax_rate' => (float) $item->tax_rate];
                }
            }

            if ($bad !== []) {
                $findings[] = ['vat_rate_mismatch', 'high', $invoiceId, 'invoice', ['jurisdiction' => $jurisdiction, 'statutory_rates' => $rates, 'items' => $bad], 0.9, 'A line uses a VAT rate that is not statutory for '.$jurisdiction.'.'];
            }
        }

        $contact = DB::table('contacts')->where('tenant_id', $tenantId)->where('id', $invoice->contact_id)->first();
        $missing = [];

        if ($contact === null || (($contact->company_name ?? '') === '' && ($contact->last_name ?? '') === '')) {
            $missing[] = 'customer_name';
        }

        if ($invoice->due_date === null) {
            $missing[] = 'due_date';
        }

        if ($jurisdiction === 'IT' && $contact !== null && ($contact->country ?? 'IT') === 'IT') {
            if (($contact->vat_number ?? '') === '' && ($contact->fiscal_code ?? '') === '') {
                $missing[] = 'customer_vat_or_fiscal_code';
            }

            if (($contact->sdi_code ?? '') === '' && ($contact->pec ?? '') === '') {
                $missing[] = 'sdi_code_or_pec';
            }
        }

        if ($missing !== []) {
            $target = $jurisdiction === 'CH' ? 'Swiss QR-bill' : 'SDI XML';
            $findings[] = ['missing_statutory_fields', 'normal', $invoiceId, 'invoice', ['missing' => $missing, 'target' => $target], 0.85, "Fields required for the {$target} are missing: ".implode(', ', $missing).'.'];
        }

        return $this->emit($tenantId, $findings);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>, 5: float, 6: string}>  $findings
     * @return list<AiSuggestion>
     */
    private function emit(string $tenantId, array $findings): array
    {
        $created = [];

        foreach ($findings as [$rule, $severity, $subjectId, $subjectType, $details, $confidence, $reasoning]) {
            $created[] = $this->suggestions->propose(new SuggestionProposal(
                $tenantId,
                SuggestionKind::Anomaly,
                'system',
                ['rule' => $rule, 'severity' => $severity, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'details' => $details],
                $confidence,
                $rule.':'.$subjectId,
                null,
                [],
                null,
                $reasoning,
                [['type' => $subjectType, 'reference' => $subjectId]],
                in_array($severity, ['critical', 'high'], true) ? 'high' : 'normal',
            ));
        }

        return $created;
    }

    /**
     * @param  list<float>  $rates
     */
    private function isStatutoryRate(float $rate, array $rates): bool
    {
        foreach ($rates as $statutory) {
            if (abs($rate - $statutory) < 0.001) {
                return true;
            }
        }

        return false;
    }

    private function normalizeNumber(string $number): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));
    }

    private function normalizeIban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    private function mask(string $iban): string
    {
        return substr($iban, 0, 4).str_repeat('*', max(0, strlen($iban) - 8)).substr($iban, -4);
    }

    /**
     * The payment IBAN of a received invoice: line 4 of a Swiss QR-bill payload or the IBAN tag of a FatturaPA XML.
     */
    private function incomingIban(object $invoice): ?string
    {
        $qr = (string) ($invoice->qr_raw_payload ?? '');

        if ($qr !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $qr) ?: [];

            if (($lines[0] ?? '') === 'SPC' && isset($lines[3]) && $lines[3] !== '') {
                return $this->normalizeIban($lines[3]);
            }
        }

        if (preg_match('#<IBAN>\s*([A-Za-z0-9 ]+?)\s*</IBAN>#', (string) ($invoice->xml_payload ?? ''), $match) === 1) {
            return $this->normalizeIban($match[1]);
        }

        return null;
    }
}
