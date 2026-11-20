<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Services;

use App\Actions\Expenses\CreateExpenseAction;
use App\Models\PassiveInvoice;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\GeneralLedger\Actions\PostPassiveInvoiceAction;
use Modules\OcrExtraction\Domain\Enums\DocumentCategory;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionException;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;
use Modules\Procurement\Actions\ProcessGoodsReceiptAction;
use Modules\Procurement\DTOs\ProcessGoodsReceiptDTO;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Services\RfqService;

/**
 * Turns a validated, reviewed document into its business document through the services of the owning modules, so every
 * rule of those modules (numbering, ledger posting, receiving, quotation) applies as it does for a manual entry:
 *  - vendor invoice: received supplier invoice (draft with its lines), posted to the ledger (Mod 012 / 024 / 029);
 *  - expense receipt: expense of the expense module;
 *  - transport document: inbound goods receipt (Mod 047) against the purchase order (Mod 046);
 *  - vendor quote: supplier quote on a request for quotation.
 */
final readonly class ExtractedDocumentConverter
{
    public function __construct(
        private PostPassiveInvoiceAction $ledger,
        private CreateExpenseAction $expenses,
        private ProcessGoodsReceiptAction $receipts,
        private RfqService $quotes,
    ) {}

    /**
     * @param  array{header: array<string, mixed>, lines: list<array<string, mixed>>, totals: array{net: float, vat: float, gross: float}, vendor_id: string|null, purchase_order_ids: list<string>, match: list<array<string, mixed>>}  $document
     * @param  array<string, mixed>  $reviewed
     * @return array{type: string, id: string}
     *
     * @throws OcrExtractionException when the owning module refuses the document
     */
    public function convert(DocumentExtractionJob $job, DocumentCategory $category, array $document, array $reviewed, ?string $userId): array
    {
        try {
            return match ($category) {
                DocumentCategory::VendorInvoice => $this->vendorInvoice($job, $document),
                DocumentCategory::ExpenseReceipt => $this->expense($job, $document, $reviewed, $userId),
                DocumentCategory::TransportDdt => $this->goodsReceipt($job, $document, $reviewed, $userId),
                DocumentCategory::VendorQuote => $this->quote($job, $document, $reviewed),
            };
        } catch (ProcurementRuleViolation|InvalidArgumentException $exception) {
            throw new OcrExtractionException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param  array{header: array<string, mixed>, lines: list<array<string, mixed>>, totals: array{net: float, vat: float, gross: float}, vendor_id: string|null, purchase_order_ids: list<string>, match: list<array<string, mixed>>}  $document
     * @return array{type: string, id: string}
     */
    private function vendorInvoice(DocumentExtractionJob $job, array $document): array
    {
        $header = $document['header'];
        $meta = (array) ($job->extracted_payload_json['meta'] ?? []);
        $number = trim((string) ($header['number'] ?? ''));

        if ($number === '') {
            throw new OcrExtractionException('The invoice number is missing.');
        }

        $documentType = (string) ($meta['document_type'] ?? 'TD01');
        $hash = hash('sha256', implode('|', [$document['vendor_id'], $documentType, $number, $header['issue_date'], number_format($document['totals']['gross'], 2, '.', '')]));

        if (PassiveInvoice::query()->where('document_hash', $hash)->exists()) {
            throw new OcrExtractionException("Invoice {$number} of this vendor is already registered.");
        }

        $accounts = array_values(array_unique(array_column($document['lines'], 'gl_account_id')));
        $centers = array_values(array_unique(array_filter(array_column($document['lines'], 'cost_center_id'))));

        $invoice = PassiveInvoice::create([
            'tenant_id' => $job->tenant_id,
            'supplier_id' => $document['vendor_id'],
            'invoice_number' => $number,
            'date' => $header['issue_date'],
            'due_date' => $header['due_date'] ?? null,
            'total_amount' => $document['totals']['gross'],
            'total_net' => $document['totals']['net'],
            'total_tax' => $document['totals']['vat'],
            'currency' => $header['currency'],
            'document_type' => $documentType,
            'payment_status' => 'unpaid',
            'document_hash' => $hash,
            'qr_raw_payload' => $meta['qr_raw_payload'] ?? null,
            'cost_account_id' => count($accounts) === 1 ? $accounts[0] : null,
            'cost_account_source' => count($accounts) === 1 ? 'ocr_extraction' : null,
            'cost_center_id' => count($centers) === 1 ? $centers[0] : null,
            'purchase_order_id' => $document['purchase_order_ids'][0] ?? null,
        ]);

        $invoice->lines()->createMany(array_map(static fn (array $line, int $index): array => [
            'tenant_id' => $job->tenant_id,
            'line_number' => $index + 1,
            'description' => $line['description'],
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'line_total' => $line['net_amount'],
            'tax_rate' => $line['vat_rate'],
            'vat_nature' => $line['vat_nature'],
            'cost_account_id' => $line['gl_account_id'],
            'cost_account_source' => 'ocr_extraction',
        ], $document['lines'], array_keys($document['lines'])));

        $this->ledger->execute($invoice);

        return ['type' => 'supplier_invoice', 'id' => (string) $invoice->id];
    }

    /**
     * @param  array{header: array<string, mixed>, lines: list<array<string, mixed>>, totals: array{net: float, vat: float, gross: float}, vendor_id: string|null, purchase_order_ids: list<string>, match: list<array<string, mixed>>}  $document
     * @param  array<string, mixed>  $reviewed
     * @return array{type: string, id: string}
     */
    private function expense(DocumentExtractionJob $job, array $document, array $reviewed, ?string $userId): array
    {
        // The expense category is an item of the `expense_category` lookup (global or of the tenant).
        $categories = DB::table('lookup_items')
            ->join('lookup_categories', 'lookup_categories.id', '=', 'lookup_items.category_id')
            ->where('lookup_categories.code', 'expense_category')
            ->whereNull('lookup_items.deleted_at')
            ->where(static fn ($query) => $query->where('lookup_items.tenant_id', $job->tenant_id)->orWhereNull('lookup_items.tenant_id'));

        $requested = isset($reviewed['expense_category_id']) && is_string($reviewed['expense_category_id']) ? $reviewed['expense_category_id'] : null;
        $categoryId = $requested !== null
            ? (clone $categories)->where('lookup_items.id', $requested)->value('lookup_items.id')
            : (clone $categories)->orderByDesc('lookup_items.is_default')->orderBy('lookup_items.sort_order')->value('lookup_items.id');

        if (! is_string($categoryId)) {
            throw new OcrExtractionException('Choose the expense category.');
        }

        $net = $document['totals']['net'];
        $vendor = $document['vendor_id'] !== null ? DB::table('contacts')->where('id', $document['vendor_id'])->first() : null;

        $expense = $this->expenses->execute([
            'category_id' => $categoryId,
            'date' => $document['header']['issue_date'],
            'amount_net' => $net,
            'amount_gross' => $document['totals']['gross'],
            'tax_rate' => $net > 0 ? round($document['totals']['vat'] / $net * 100, 2) : 0,
            'currency' => $document['header']['currency'],
            'merchant_name' => $vendor->company_name ?? ($job->extracted_payload_json['vendor']['name'] ?? null),
            'contact_id' => $document['vendor_id'],
            'user_id' => $userId ?? $job->requested_by_user_id,
            'notes' => 'Extracted by the AI Inbox from a receipt.',
        ]);

        return ['type' => 'expense', 'id' => (string) $expense->id];
    }

    /**
     * @param  array{header: array<string, mixed>, lines: list<array<string, mixed>>, totals: array{net: float, vat: float, gross: float}, vendor_id: string|null, purchase_order_ids: list<string>, match: list<array<string, mixed>>}  $document
     * @param  array<string, mixed>  $reviewed
     * @return array{type: string, id: string}
     */
    private function goodsReceipt(DocumentExtractionJob $job, array $document, array $reviewed, ?string $userId): array
    {
        $orderId = $document['purchase_order_ids'][0] ?? null;

        if ($orderId === null) {
            throw new OcrExtractionException('Link the transport document to a purchase order to receive the goods.');
        }

        if ($userId === null) {
            throw new OcrExtractionException('Goods are received by a user.');
        }

        $orderLines = DB::table('purchase_order_lines')->where('tenant_id', $job->tenant_id)->where('purchase_order_id', $orderId)->whereNull('deleted_at')->pluck('id')->all();
        $receiptLines = [];

        foreach ($document['lines'] as $index => $line) {
            $lineId = $line['purchase_order_line_id'];

            if ($lineId === null || ! in_array($lineId, $orderLines, true)) {
                throw new OcrExtractionException('Line '.($index + 1).' is not linked to a line of the purchase order.');
            }

            $receiptLines[] = ['purchase_order_line_id' => $lineId, 'quantity_received' => $line['quantity']];
        }

        $receipt = $this->receipts->execute(new ProcessGoodsReceiptDTO(
            purchaseOrderId: $orderId,
            supplierDeliveryNoteRef: (string) ($document['header']['number'] ?? 'n/a'),
            receivedDate: (string) $document['header']['issue_date'],
            receivedByUserId: $userId,
            lines: $receiptLines,
            warehouseId: isset($reviewed['warehouse_id']) && is_string($reviewed['warehouse_id']) ? $reviewed['warehouse_id'] : null,
            notes: 'Extracted by the AI Inbox from a transport document.',
            tenantId: (string) $job->tenant_id,
        ));

        return ['type' => 'purchase_receipt', 'id' => (string) $receipt->id];
    }

    /**
     * @param  array{header: array<string, mixed>, lines: list<array<string, mixed>>, totals: array{net: float, vat: float, gross: float}, vendor_id: string|null, purchase_order_ids: list<string>, match: list<array<string, mixed>>}  $document
     * @param  array<string, mixed>  $reviewed
     * @return array{type: string, id: string}
     */
    private function quote(DocumentExtractionJob $job, array $document, array $reviewed): array
    {
        $rfqId = $reviewed['rfq_id'] ?? null;
        $rfq = is_string($rfqId) ? Rfq::query()->where('tenant_id', $job->tenant_id)->find($rfqId) : null;

        if ($rfq === null) {
            throw new OcrExtractionException('Choose the request for quotation the quote answers.');
        }

        // A procurement supplier shares the id of its contact.
        $supplierId = DB::table('suppliers')->where('tenant_id', $job->tenant_id)->where('id', $document['vendor_id'])->whereNull('deleted_at')->value('id');

        if (! is_string($supplierId)) {
            throw new OcrExtractionException('The vendor is not a supplier of the procurement module.');
        }

        $lines = [];

        foreach ($document['lines'] as $index => $line) {
            if ($line['rfq_line_id'] === null) {
                throw new OcrExtractionException('Line '.($index + 1).' is not linked to a line of the request for quotation.');
            }

            $lines[] = ['rfq_line_id' => $line['rfq_line_id'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'tax_rate' => $line['vat_rate']];
        }

        $quote = $this->quotes->recordQuote($rfq, [
            'supplier_id' => $supplierId,
            'quote_reference' => $document['header']['number'] ?? null,
            'quote_date' => $document['header']['issue_date'],
            'valid_until' => $document['header']['due_date'] ?? null,
            'notes' => 'Extracted by the AI Inbox from a quotation.',
            'lines' => $lines,
        ]);

        return ['type' => 'vendor_quote', 'id' => (string) $quote->id];
    }
}
