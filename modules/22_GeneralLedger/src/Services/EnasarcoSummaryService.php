<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\Contact;
use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SDI\Enums\TipoRitenuta;

/**
 * Enasarco of a period: what the principals kept from the tenant as an agent (issued invoices), and what the tenant kept
 * from its agents (received invoices with a RT04 block).
 */
final class EnasarcoSummaryService
{
    /**
     * @return array{year: int, quarter: ?int, from: string, to: string, withheld_from_tenant: array<string, mixed>, withheld_by_tenant: array<string, mixed>}
     */
    public function summary(string $tenantId, int $year, ?int $quarter = null): array
    {
        $from = CarbonImmutable::create($year, $quarter === null ? 1 : (($quarter - 1) * 3) + 1, 1);
        $to = $quarter === null ? $from->endOfYear() : $from->addMonthsNoOverflow(2)->endOfMonth();

        return [
            'year' => $year,
            'quarter' => $quarter,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'withheld_from_tenant' => $this->onIssuedInvoices($tenantId, $from, $to),
            'withheld_by_tenant' => $this->onReceivedInvoices($tenantId, $from, $to),
        ];
    }

    /**
     * @return array{total_base: string, total_amount: string, by_contact: list<array<string, mixed>>}
     */
    private function onIssuedInvoices(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $invoices = SalesInvoice::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('enasarco_enabled', true)
            ->where('status', 'posted')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['id', 'contact_id', 'invoice_type', 'enasarco_base', 'enasarco_amount']);

        $rows = [];

        foreach ($invoices as $invoice) {
            $row = $rows[$invoice->contact_id] ?? ['base' => '0', 'amount' => '0', 'documents' => 0];
            $sign = $invoice->invoice_type === 'credit_note' ? '-1' : '1';
            $rows[$invoice->contact_id] = [
                'base' => Decimal::add($row['base'], Decimal::mul((string) ($invoice->enasarco_base ?? '0'), $sign)),
                'amount' => Decimal::add($row['amount'], Decimal::mul((string) ($invoice->enasarco_amount ?? '0'), $sign)),
                'documents' => $row['documents'] + 1,
            ];
        }

        return $this->totals($rows);
    }

    /**
     * @return array{total_base: string, total_amount: string, by_contact: list<array<string, mixed>>}
     */
    private function onReceivedInvoices(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $invoices = PassiveInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('document_type', '!=', 'TD04')
            ->where('withholding_tax_amount', '>', 0)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['id', 'supplier_id', 'total_net', 'withholdings']);

        $rows = [];

        foreach ($invoices as $invoice) {
            foreach ($invoice->withholdings ?? [] as $withholding) {
                if ($withholding['type'] !== TipoRitenuta::Rt04->value) {
                    continue;
                }

                $row = $rows[$invoice->supplier_id] ?? ['base' => '0', 'amount' => '0', 'documents' => 0];
                $rows[$invoice->supplier_id] = [
                    'base' => Decimal::add($row['base'], (string) ($invoice->total_net ?? '0')),
                    'amount' => Decimal::add($row['amount'], (string) $withholding['amount']),
                    'documents' => $row['documents'] + 1,
                ];
            }
        }

        return $this->totals($rows);
    }

    /**
     * @param  array<string, array{base: string, amount: string, documents: int}>  $rows
     * @return array{total_base: string, total_amount: string, by_contact: list<array<string, mixed>>}
     */
    private function totals(array $rows): array
    {
        $names = Contact::withoutGlobalScopes()->whereIn('id', array_keys($rows))->get()->keyBy('id');
        $totalBase = '0';
        $totalAmount = '0';
        $byContact = [];

        foreach ($rows as $contactId => $row) {
            $contact = $names->get($contactId);
            $totalBase = Decimal::add($totalBase, $row['base']);
            $totalAmount = Decimal::add($totalAmount, $row['amount']);
            $byContact[] = [
                'contact_id' => (string) $contactId,
                'contact_name' => $contact?->company_name ?: trim($contact?->first_name.' '.$contact?->last_name),
                'documents' => $row['documents'],
                'base' => Decimal::round2($row['base']),
                'amount' => Decimal::round2($row['amount']),
            ];
        }

        return ['total_base' => Decimal::round2($totalBase), 'total_amount' => Decimal::round2($totalAmount), 'by_contact' => $byContact];
    }
}
