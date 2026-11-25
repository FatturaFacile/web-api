<?php

declare(strict_types=1);

namespace Modules\Returns\Services;

use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Returns\Models\CustomerReturn;
use Modules\Returns\Models\ReturnReasonCode;
use Modules\Returns\Support\Decimal;
use Modules\Returns\Support\ReturnsError;

/**
 * Credit Note Fiscal Time-Limit Control (Mod 232):
 * - Italy (Art. 26, commi 2 & 3 DPR 633/72): checks if more than 1 year has elapsed from the original sales
 *   invoice date for commercial agreements / returned goods; if > 1 year, warns and proposes issuing a
 *   taxable-base-only adjustment outside VAT recovery with nature code N2.2 and <DatiFattureCollegate>.
 * - Switzerland (Art. 41 LIVA / MWSTG): enforces documentary traceability of consideration reduction.
 */
final readonly class CreditNoteFiscalControlService
{
    /** @var list<string> */
    private const array CommercialReturnReasons = [
        'right_of_withdrawal',
        'commercial_return',
        'changed_mind',
        'not_as_described',
        'wrong_item',
        'wrong_shipment',
        'other',
    ];

    public function __construct(
        private InvoicingServiceInterface $invoicing,
        private ReturnsLedger $ledger,
    ) {}

    /**
     * Preview the fiscal check for issuing a Credit Note (TD04) from a CustomerReturn.
     *
     * @return array<string, mixed>
     */
    public function check(string $tenantId, CustomerReturn $return, ?string $creditDate = null): array
    {
        $return->loadMissing(['lines', 'invoice', 'contact']);
        $originalInvoice = $return->invoice;

        if ($originalInvoice === null) {
            throw ReturnsError::on('invoice_id', 'credit_note.original_invoice_required');
        }

        $tenant = Tenant::query()->find($tenantId);
        $jurisdiction = strtoupper((string) ($originalInvoice->jurisdiction ?: $tenant?->country ?: 'IT'));
        $issueDate = Carbon::parse((string) $originalInvoice->date)->startOfDay();
        $targetDate = Carbon::parse($creditDate ?? now()->toDateString())->startOfDay();

        $daysElapsed = max(0, (int) $issueDate->diffInDays($targetDate, false));
        $oneYearExceeded = $issueDate->copy()->addYear()->lt($targetDate);

        $reason = ReturnReasonCode::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $return->reason_code)
            ->first();
        $isCommercialAgreement = in_array($return->reason_code, self::CommercialReturnReasons, true)
            || ($reason !== null && ! $reason->is_defect);

        $art26BlockVatRecovery = $jurisdiction === 'IT' && $oneYearExceeded && $isCommercialAgreement;
        $vatRecoverable = ! $art26BlockVatRecovery;
        $proposedNatura = $art26BlockVatRecovery ? 'N2.2' : null;
        $proposedTaxRate = $art26BlockVatRecovery ? '0.00' : null;

        $proposedLines = [];
        $netTotal = '0';
        $vatTotal = '0';

        foreach ($return->lines as $line) {
            $qty = Decimal::isPositive($line->quantity_inspected) ? $line->quantity_inspected : $line->quantity;
            $lineNet = Decimal::round(Decimal::mul($qty, $line->unit_price), 2);
            $effectiveTaxRate = $art26BlockVatRecovery ? '0.00' : Decimal::round($line->tax_rate ?: '22.00', 2);
            $lineVat = Decimal::round(Decimal::mul($lineNet, Decimal::div($effectiveTaxRate, '100')), 2);

            $netTotal = Decimal::add($netTotal, $lineNet);
            $vatTotal = Decimal::add($vatTotal, $lineVat);

            $proposedLines[] = [
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => $qty,
                'unit_price' => $line->unit_price,
                'tax_rate' => $effectiveTaxRate,
                'natura' => $art26BlockVatRecovery ? 'N2.2' : null,
                'line_net' => $lineNet,
                'line_vat' => $lineVat,
            ];
        }

        if (Decimal::isPositive($return->restocking_fee_amount)) {
            $feeNet = Decimal::round('-'.(string) $return->restocking_fee_amount, 2);
            $netTotal = Decimal::add($netTotal, $feeNet);
        }

        $grossTotal = Decimal::round(Decimal::add($netTotal, $vatTotal), 2);

        return [
            'customer_return_id' => $return->id,
            'rma_number' => $return->rma_number,
            'jurisdiction' => $jurisdiction,
            'original_invoice_id' => (string) $originalInvoice->id,
            'original_invoice_number' => (string) $originalInvoice->invoice_number,
            'original_invoice_date' => $issueDate->toDateString(),
            'credit_note_date' => $targetDate->toDateString(),
            'days_elapsed' => $daysElapsed,
            'art26_one_year_exceeded' => $oneYearExceeded,
            'is_commercial_agreement' => $isCommercialAgreement,
            'vat_recoverable' => $vatRecoverable,
            'proposed_document_type' => $jurisdiction === 'IT' ? 'TD04' : 'credit_note',
            'proposed_natura' => $proposedNatura,
            'proposed_tax_rate' => $proposedTaxRate,
            'warning_code' => $art26BlockVatRecovery ? 'IT_ART26_OVER_ONE_YEAR_N2_2' : ($jurisdiction === 'CH' ? 'CH_ART41_LIVA_TRACEABILITY' : null),
            'warning_message' => $art26BlockVatRecovery
                ? __('returns_errors.credit_note.art26_over_one_year', [
                    'invoice' => $originalInvoice->invoice_number,
                    'date' => $issueDate->toDateString(),
                    'days' => $daysElapsed,
                ])
                : ($jurisdiction === 'CH'
                    ? __('returns_errors.credit_note.art41_liva_notice', ['invoice' => $originalInvoice->invoice_number])
                    : null),
            'dati_fatture_collegate' => [
                'id_documento' => (string) $originalInvoice->invoice_number,
                'data' => $issueDate->toDateString(),
                'rma_number' => $return->rma_number,
            ],
            'totals' => [
                'net' => Decimal::round($netTotal, 2),
                'vat' => Decimal::round($vatTotal, 2),
                'gross' => $grossTotal,
            ],
            'proposed_lines' => $proposedLines,
        ];
    }

    /**
     * Issue a Credit Note (TD04 in IT or Art. 41 LIVA adjustment in CH) linked to the return and original invoice.
     *
     * @param  array<string, mixed>  $data
     */
    public function issueCreditNote(string $tenantId, CustomerReturn $return, array $data, ?string $userId): SalesInvoice
    {
        return DB::transaction(function () use ($tenantId, $return, $data, $userId): SalesInvoice {
            $locked = CustomerReturn::query()->with(['lines', 'invoice'])->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($locked->credit_note_id !== null) {
                throw ReturnsError::on('customer_return_id', 'credit_note.already_issued');
            }

            $check = $this->check($tenantId, $locked, $data['date'] ?? null);
            $applyN22 = (bool) ($data['apply_n2_2'] ?? ($check['proposed_natura'] === 'N2.2'));

            $items = [];
            foreach ($check['proposed_lines'] as $line) {
                $taxRate = $applyN22 ? '0.00' : $line['tax_rate'];
                $natura = $applyN22 ? 'N2.2' : $line['natura'];
                $items[] = [
                    'product_id' => $line['product_id'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'tax_rate' => $taxRate,
                    'natura' => $natura,
                ];
            }

            /** @var Invoice $originalInvoice */
            $originalInvoice = $locked->invoice;
            $creditDate = (string) ($data['date'] ?? now()->toDateString());
            $jurisdiction = (string) $check['jurisdiction'];

            $creditNote = $this->invoicing->createInvoice([
                'tenant_id' => $tenantId,
                'contact_id' => (string) $locked->contact_id,
                'invoice_type' => 'credit_note',
                'document_type' => $jurisdiction === 'IT' ? 'TD04' : null,
                'related_invoice_id' => (string) $originalInvoice->id,
                'linked_document_number' => (string) $originalInvoice->invoice_number,
                'linked_document_date' => $originalInvoice->date->toDateString(),
                'vat_nature' => $applyN22 ? 'N2.2' : null,
                'jurisdiction' => $jurisdiction,
                'date' => $creditDate,
                'currency' => $originalInvoice->currency ?? 'EUR',
                'sdi_extra' => [
                    'dati_fatture_collegate' => $check['dati_fatture_collegate'],
                    'art26_one_year_exceeded' => $check['art26_one_year_exceeded'],
                    'art41_liva_traceability' => $jurisdiction === 'CH' ? [
                        'original_invoice' => $originalInvoice->invoice_number,
                        'original_date' => $originalInvoice->date->toDateString(),
                        'rma_number' => $locked->rma_number,
                        'reason_code' => $locked->reason_code,
                    ] : null,
                ],
                'notes' => $data['notes'] ?? __('returns_errors.credit_note.default_note', [
                    'rma' => $locked->rma_number,
                    'invoice' => $originalInvoice->invoice_number,
                ]),
                'skip_charge_lines' => true,
                'created_by' => $userId,
            ], $items);

            $locked->update([
                'credit_note_id' => (string) $creditNote->id,
                'credit_note_reference' => (string) $creditNote->invoice_number,
                'resolution_type' => 'credit_note',
                'status' => CustomerReturn::StatusResolved,
                'resolved_at' => now(),
            ]);

            $this->ledger->record('returns.credit_note_issued', $locked, [
                'credit_note_id' => (string) $creditNote->id,
                'art26_one_year_exceeded' => $check['art26_one_year_exceeded'],
                'applied_natura' => $applyN22 ? 'N2.2' : null,
            ]);

            return $creditNote;
        });
    }
}
