<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Actions;

use App\Models\PosSale;
use App\Models\Tenant;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Tenancy\DocumentQuotaService;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\FiscalRetail\Exceptions\CorrispettiviGatewayException;
use Modules\FiscalRetail\Models\CommercialDocument;
use Modules\FiscalRetail\Models\CommercialDocumentLine;
use Modules\FiscalRetail\Models\DailyCorrispettivo;
use Modules\FiscalRetail\Services\CorrispettiviDayLock;
use Modules\FiscalRetail\Services\CorrispettiviGatewayManager;
use Modules\FiscalRetail\Services\CorrispettiviTransmitter;

/**
 * Issues commercial documents and the void and return documents that correct them. A document is never edited: every
 * correction is a new numbered document that refers to the original.
 *
 * A document is stored as `pending` and handed to the gateway only after the commit (see CorrispettiviTransmitter):
 * when the gateway fails the document stays issued, with status `failed`, and is sent again later.
 */
final readonly class IssueCommercialDocument
{
    public function __construct(
        private DocumentNumberingService $numbering,
        private CorrispettiviGatewayManager $gateways,
        private DocumentQuotaService $quota,
        private TenantContext $tenantContext,
        private CorrispettiviDayLock $dayLock,
        private TenantClock $clock,
        private CorrispettiviTransmitter $transmitter,
    ) {}

    /**
     * @param  list<array{description: string, quantity: numeric-string|int|float, unit_price: numeric-string|int|float, tax_rate: numeric-string|int|float, vat_nature?: ?string}>  $lines
     */
    public function sale(array $lines, ?string $paymentMethod = null, ?string $posSaleId = null): CommercialDocument
    {
        $this->quota->assertCanIssue($this->tenant(), DocumentQuotaService::CommercialDocumentPoints);

        return DB::transaction(fn (): CommercialDocument => $this->store('sale', array_map($this->priced(...), $lines), $paymentMethod, $posSaleId));
    }

    /**
     * The commercial document of a POS sale. Calling it twice, also at the same time, returns the document already
     * issued: the sale row is locked while the document is checked and created, and a partial unique index on
     * `pos_sale_id` stops any other path. A refunded sale gets no document.
     */
    public function fromPosSale(PosSale $sale): CommercialDocument
    {
        $issued = static fn (): ?CommercialDocument => CommercialDocument::where('pos_sale_id', $sale->id)->where('document_type', 'sale')->first();

        try {
            return DB::transaction(function () use ($sale, $issued): CommercialDocument {
                $sale = PosSale::lockForUpdate()->findOrFail($sale->id);

                if (($existing = $issued()) !== null) {
                    return $existing;
                }

                if ($sale->status === 'refunded') {
                    throw ValidationException::withMessages(['pos_sale_id' => __('sales.commercial_document_refunded_sale')]);
                }

                $this->quota->assertCanIssue($this->tenant(), DocumentQuotaService::CommercialDocumentPoints);

                $lines = $sale->items()->get()->map(static fn ($item): array => [
                    'description' => (string) $item->description,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'tax_rate' => (string) $item->tax_rate,
                ])->all();

                return $this->store('sale', array_map($this->priced(...), $lines), (string) $sale->payment_method, (string) $sale->id);
            });
        } catch (UniqueConstraintViolationException $e) {
            return $issued() ?? throw $e;
        }
    }

    /**
     * Cancels a whole sale document that nothing has corrected yet.
     */
    public function void(CommercialDocument $document): CommercialDocument
    {
        return DB::transaction(function () use ($document): CommercialDocument {
            $document = $this->lockedSale($document);

            if (CommercialDocument::where('related_document_id', $document->id)->exists()) {
                throw ValidationException::withMessages(['document' => __('sales.commercial_document_already_corrected')]);
            }

            $lines = $document->lines->map(fn (CommercialDocumentLine $line): array => $this->copied($line, (string) $line->quantity))->all();

            return $this->store('void', $lines, $document->payment_method, null, $document->id);
        });
    }

    /**
     * Records goods given back, in whole or in part. Without lines every quantity still open is returned.
     *
     * @param  list<array{line_id: string, quantity: numeric-string|int|float}>  $returned
     */
    public function return(CommercialDocument $document, array $returned = []): CommercialDocument
    {
        return DB::transaction(function () use ($document, $returned): CommercialDocument {
            $document = $this->lockedSale($document);

            if (CommercialDocument::where('related_document_id', $document->id)->where('document_type', 'void')->exists()) {
                throw ValidationException::withMessages(['document' => __('sales.commercial_document_already_corrected')]);
            }

            $requested = collect($returned)->mapWithKeys(static fn (array $line): array => [$line['line_id'] => (string) $line['quantity']]);
            $lines = [];

            foreach ($document->lines as $line) {
                $alreadyReturned = (string) CommercialDocumentLine::where('source_line_id', $line->id)->sum('quantity');
                $open = bcsub((string) $line->quantity, $alreadyReturned, 4);
                $quantity = $returned === [] ? $open : $requested->pull($line->id, '0');

                if (bccomp($quantity, '0', 4) <= 0) {
                    continue;
                }

                if (bccomp($quantity, $open, 4) === 1) {
                    throw ValidationException::withMessages(['lines' => __('sales.commercial_document_return_exceeds')]);
                }

                $lines[] = $this->copied($line, $quantity);
            }

            if ($lines === [] || $requested->isNotEmpty()) {
                throw ValidationException::withMessages(['lines' => __('sales.commercial_document_return_empty')]);
            }

            return $this->store('return', $lines, $document->payment_method, null, $document->id);
        });
    }

    private function lockedSale(CommercialDocument $document): CommercialDocument
    {
        $document = CommercialDocument::with('lines')->lockForUpdate()->findOrFail($document->id);

        if ($document->document_type !== 'sale') {
            throw ValidationException::withMessages(['document' => __('sales.commercial_document_not_a_sale')]);
        }

        return $document;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function store(string $type, array $lines, ?string $paymentMethod, ?string $posSaleId, ?string $relatedId = null): CommercialDocument
    {
        $businessDay = $this->clock->today($this->tenant());
        $today = $businessDay->toDateString();
        $this->dayLock->acquire((string) $this->tenant()->id, $today);

        if (DailyCorrispettivo::where('business_date', $today)->exists()) {
            throw ValidationException::withMessages(['document' => __('sales.corrispettivi_day_closed')]);
        }

        try {
            $gateway = $this->gateways->gateway();
            $gateway->assertAvailable();
        } catch (CorrispettiviGatewayException $e) {
            throw ValidationException::withMessages(['gateway' => $e->getMessage()]);
        }

        $sum = static fn (string $field): string => array_reduce($lines, static fn (string $total, array $line): string => bcadd($total, $line[$field], 2), '0.00');
        $year = (int) $businessDay->format('Y');
        $number = $this->numbering->next('commercial_document', $year);
        $tenant = $this->tenant();

        $document = CommercialDocument::create([
            'pos_sale_id' => $posSaleId,
            'related_document_id' => $relatedId,
            'document_type' => $type,
            'fiscal_year' => $year,
            'sequential_number' => $number['sequential_number'],
            'document_number' => $number['number'],
            'issue_date' => $today,
            'payment_method' => $paymentMethod,
            'total_net' => $sum('net_amount'),
            'total_tax' => $sum('tax_amount'),
            'total_gross' => $sum('gross_amount'),
            'gateway' => $gateway->name(),
            'gateway_status' => CorrispettiviTransmitter::StatusPending,
            'transmission_key' => (string) Str::uuid(),
        ]);

        $document->lines()->createMany(array_map(static fn (array $line): array => [...$line, 'tenant_id' => $tenant->id], $lines));
        $document->load('lines');

        // Sent only once the outermost transaction has committed: the gateway is never called while the numbering and
        // the day lock are held, and a rolled back document is never transmitted.
        DB::afterCommit(fn () => $this->transmitter->transmitDocument($document));

        return $document;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function priced(array $line): array
    {
        $quantity = (string) $line['quantity'];
        $unitPrice = (string) $line['unit_price'];
        $rate = (string) $line['tax_rate'];
        $net = bcround(bcmul($quantity, $unitPrice, 6), 2);
        $tax = bcround(bcdiv(bcmul($net, $rate, 6), '100', 6), 2);

        return [
            'description' => $line['description'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $rate,
            'vat_nature' => $line['vat_nature'] ?? null,
            'net_amount' => $net,
            'tax_amount' => $tax,
            'gross_amount' => bcadd($net, $tax, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function copied(CommercialDocumentLine $line, string $quantity): array
    {
        return [
            ...$this->priced([
                'description' => $line->description,
                'quantity' => $quantity,
                'unit_price' => (string) $line->unit_price,
                'tax_rate' => (string) $line->tax_rate,
                'vat_nature' => $line->vat_nature,
            ]),
            'source_line_id' => $line->id,
        ];
    }

    private function tenant(): Tenant
    {
        return $this->tenantContext->getTenant() ?? throw new \RuntimeException('Active tenant context is required.');
    }
}
