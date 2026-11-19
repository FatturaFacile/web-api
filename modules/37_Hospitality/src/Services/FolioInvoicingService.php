<?php

declare(strict_types=1);

namespace Modules\Hospitality\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Hospitality\Models\FolioEntry;
use Modules\Hospitality\Models\Stay;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;

/**
 * The folio of a stay as a statement, and as a sales invoice once the stay is checked out. The invoice is written by the
 * invoicing contract (numbering, VAT, ledger posting), never by this module. The tax rates are chosen by the caller: the
 * folio lines carry none.
 */
final class FolioInvoicingService
{
    public function __construct(private readonly InvoicingServiceInterface $invoicing) {}

    /**
     * The statement of a stay: charges, payments, totals per category and the balance (charges less payments). Voided
     * lines are left out.
     *
     * @return array{stay: array<string, mixed>, charges: list<array<string, mixed>>, payments: list<array<string, mixed>>, totals: array{charges: string, payments: string, balance: string, by_category: array<string, string>}}
     */
    public function statement(Stay $stay): array
    {
        $entries = $stay->folioEntries()->whereNull('voided_at')->get();
        $charges = $entries->where('entry_type', 'charge')->values();
        $payments = $entries->where('entry_type', 'payment')->values();

        $byCategory = [];
        foreach ($charges as $entry) {
            $byCategory[$entry->category] = Decimal::add($byCategory[$entry->category] ?? '0', (string) $entry->amount);
        }

        $chargeTotal = $this->sum($charges->all());
        $paymentTotal = $this->sum($payments->all());

        return [
            'stay' => [
                'id' => $stay->id,
                'number' => $stay->number,
                'guest_name' => $stay->guest_name,
                'check_in_date' => $stay->check_in_date->toDateString(),
                'check_out_date' => $stay->check_out_date->toDateString(),
                'status' => $stay->status,
                'invoice_id' => $stay->invoice_id,
            ],
            'charges' => $charges->map(fn (FolioEntry $entry): array => $this->line($entry))->all(),
            'payments' => $payments->map(fn (FolioEntry $entry): array => $this->line($entry))->all(),
            'totals' => [
                'charges' => Decimal::round2($chargeTotal),
                'payments' => Decimal::round2($paymentTotal),
                'balance' => Decimal::round2(Decimal::sub($chargeTotal, $paymentTotal)),
                'by_category' => array_map(static fn (string $amount): string => Decimal::round2($amount), $byCategory),
            ],
        ];
    }

    /**
     * Invoices the charges of a checked-out stay, once, to the given customer. Every charge becomes a line with the tax
     * rate of its category (or the default rate).
     *
     * @param  array{contact_id: string, tax_rate: string, category_tax_rates?: array<string, string>, date?: ?string}  $data
     */
    public function invoice(Stay $stay, array $data): Stay
    {
        return DB::transaction(function () use ($stay, $data): Stay {
            $locked = Stay::query()->lockForUpdate()->findOrFail($stay->id);

            if ($locked->invoice_id !== null) {
                throw new BusinessRuleException('The folio is already invoiced.');
            }

            if ($locked->status !== 'checked_out') {
                throw new BusinessRuleException('Only the folio of a checked-out stay is invoiced.');
            }

            $charges = $locked->folioEntries()->whereNull('voided_at')->where('entry_type', 'charge')->get();

            if ($charges->isEmpty()) {
                throw new BusinessRuleException('The folio has no charges to invoice.');
            }

            $rates = $data['category_tax_rates'] ?? [];
            $items = $charges->map(static fn (FolioEntry $entry): array => [
                'description' => (string) $entry->description,
                'quantity' => (string) $entry->quantity,
                'unit_price' => (string) $entry->unit_amount,
                'tax_rate' => (string) ($rates[$entry->category] ?? $data['tax_rate']),
            ])->values()->all();

            $invoice = $this->invoicing->issueInvoice([
                'tenant_id' => (string) $locked->tenant_id,
                'contact_id' => $data['contact_id'],
                'invoice_type' => 'standard',
                'currency' => (string) (Tenant::query()->whereKey($locked->tenant_id)->value('default_currency') ?? 'CHF'),
                'date' => $data['date'] ?? null,
                'notes' => "Stay {$locked->number}, guest {$locked->guest_name}, {$locked->check_in_date->toDateString()} - {$locked->check_out_date->toDateString()}",
            ], $items, (string) (auth()->id() ?? 'system'));

            $locked->update(['invoice_id' => $invoice->id]);

            return $locked->refresh();
        });
    }

    /**
     * @param  list<FolioEntry>  $entries
     */
    private function sum(array $entries): string
    {
        return array_reduce($entries, static fn (string $sum, FolioEntry $entry): string => Decimal::add($sum, (string) $entry->amount), '0');
    }

    /**
     * @return array<string, mixed>
     */
    private function line(FolioEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'category' => $entry->category,
            'description' => $entry->description,
            'quantity' => (string) $entry->quantity,
            'unit_amount' => (string) $entry->unit_amount,
            'amount' => (string) $entry->amount,
            'payment_method' => $entry->payment_method,
            'posted_on' => $entry->posted_on->toDateString(),
        ];
    }
}
