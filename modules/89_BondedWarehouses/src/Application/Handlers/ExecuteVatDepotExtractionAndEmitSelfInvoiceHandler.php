<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Handlers;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\BondedWarehouses\Application\Commands\ExecuteVatDepotExtractionAndEmitSelfInvoiceCommand;
use Modules\BondedWarehouses\Application\Services\VatDepotRegisterService;
use Modules\BondedWarehouses\Domain\Enums\VatDepotMovementType;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;
use Modules\BondedWarehouses\Domain\Support\VatDepotCalculator;
use Modules\BondedWarehouses\Events\VatDepotExtractionEmitted;
use Modules\BondedWarehouses\Models\VatDepotRegisterEntry;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Extracts goods from an IVA warehouse (Art. 50-bis DL 331/93): the taxable base is the value of the goods plus the storage and processing
 * services, the register row is appended and the electronic self-invoice (TD22, or TD23 with the non-compensable F24 payment) is created with
 * the existing invoicing service, all in one transaction. The company is both transferor and transferee of the self-invoice: its own contact
 * is found by VAT number unless one is named.
 */
final class ExecuteVatDepotExtractionAndEmitSelfInvoiceHandler
{
    public function __construct(
        private readonly VatDepotRegisterService $register,
        private readonly InvoicingServiceInterface $invoicing,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Calculates the extraction without writing anything.
     *
     * @return array<string, mixed>
     *
     * @throws BondedWarehouseException
     */
    public function preview(ExecuteVatDepotExtractionAndEmitSelfInvoiceCommand $command): array
    {
        $this->assertCommand($command);
        $calculation = VatDepotCalculator::extraction($command->baseGoodsValue, $command->storageAndProcessingServicesValue, $command->vatRatePercent);
        $balance = $this->register->balance($command->authorizationId, $command->productId, $command->lotReference);

        return [
            'total_taxable_extraction_base' => $calculation['base'],
            'vat_rate_percent' => $command->vatRatePercent,
            'vat_amount' => $calculation['vat_amount'],
            'total_with_vat' => $calculation['total'],
            'sdi_document_type' => VatDepotCalculator::documentTypeOf($command->movementType)->value,
            'available_quantity' => $balance,
            'sufficient_stock' => $balance + 0.00005 >= $command->quantity,
            'requires_f24' => $command->movementType === VatDepotMovementType::ExtractionTD23,
        ];
    }

    /**
     * @throws BondedWarehouseException
     */
    public function handle(ExecuteVatDepotExtractionAndEmitSelfInvoiceCommand $command): VatDepotRegisterEntry
    {
        $this->assertCommand($command);

        return DB::transaction(function () use ($command): VatDepotRegisterEntry {
            $authorization = $this->register->lockDepot($command->authorizationId, $command->movementDate);
            $balance = $this->register->balance((string) $authorization->id, $command->productId, $command->lotReference);

            if ($balance + 0.00005 < $command->quantity) {
                throw BondedWarehouseException::because('insufficient_depot_stock', ['available' => $balance, 'requested' => $command->quantity]);
            }

            $calculation = VatDepotCalculator::extraction($command->baseGoodsValue, $command->storageAndProcessingServicesValue, $command->vatRatePercent);
            $documentType = VatDepotCalculator::documentTypeOf($command->movementType);
            $invoice = $this->emitSelfInvoice($command, $documentType->value, $calculation['base'], (string) $authorization->tenant_id);

            $entry = VatDepotRegisterEntry::query()->create([
                'tenant_id' => $authorization->tenant_id,
                'authorization_id' => $authorization->id,
                'register_progressive_number' => $this->register->nextProgressive((string) $authorization->id),
                'movement_type' => $command->movementType,
                'movement_date' => $command->movementDate,
                'product_id' => $command->productId,
                'lot_reference' => $command->lotReference,
                'quantity' => $command->quantity,
                'base_goods_value' => $command->baseGoodsValue,
                'storage_and_processing_services_value' => $command->storageAndProcessingServicesValue,
                'total_taxable_extraction_base' => $calculation['base'],
                'vat_rate_percent' => $command->vatRatePercent,
                'vat_amount' => $calculation['vat_amount'],
                'self_invoice_id' => $invoice->id,
                'sdi_document_type' => $documentType,
                'f24_payment_reference' => $command->f24PaymentReference,
                'f24_payment_date' => $command->f24PaymentDate,
                'notes' => $command->notes,
                'created_by' => $command->userId,
            ])->refresh();

            event(new VatDepotExtractionEmitted((string) $entry->id, (string) $authorization->id, (string) $invoice->id, $documentType->value, $calculation['vat_amount']));

            return $entry;
        });
    }

    private function assertCommand(ExecuteVatDepotExtractionAndEmitSelfInvoiceCommand $command): void
    {
        if (! $command->movementType->isExtraction()) {
            throw BondedWarehouseException::because('movement_not_extraction');
        }

        if ($command->quantity <= 0.0) {
            throw BondedWarehouseException::because('quantity_must_be_positive');
        }

        if ($command->baseGoodsValue < 0.0 || $command->storageAndProcessingServicesValue < 0.0) {
            throw BondedWarehouseException::because('negative_value');
        }

        VatDepotCalculator::assertPaymentMode($command->movementType, $command->f24PaymentReference, $command->f24PaymentDate);
    }

    private function emitSelfInvoice(ExecuteVatDepotExtractionAndEmitSelfInvoiceCommand $command, string $documentType, float $base, string $tenantId): SalesInvoice
    {
        $contactId = $this->selfContactId($command, $tenantId);
        $zeroRate = $command->vatRatePercent <= 0.0;
        $items = [[
            'product_id' => $command->productId,
            'description' => __('bonded_warehouses.invoice.goods_line', ['lot' => $command->lotReference ?? '-', 'quantity' => $command->quantity]),
            'quantity' => 1,
            'unit_price' => number_format($command->baseGoodsValue, 2, '.', ''),
            'tax_rate' => $command->vatRatePercent,
        ]];

        if ($command->storageAndProcessingServicesValue > 0.0) {
            $items[] = [
                'description' => __('bonded_warehouses.invoice.services_line'),
                'quantity' => 1,
                'unit_price' => number_format($command->storageAndProcessingServicesValue, 2, '.', ''),
                'tax_rate' => $command->vatRatePercent,
            ];
        }

        return $this->invoicing->createInvoice([
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'invoice_type' => 'standard',
            'document_type' => $documentType,
            'date' => $command->movementDate,
            'currency' => 'EUR',
            'jurisdiction' => 'IT',
            'vat_nature' => $zeroRate ? VatDepotCalculator::LETTER_OF_INTENT_NATURE : null,
            'declaration_of_intent_id' => $command->declarationOfIntentId,
            'notes' => __('bonded_warehouses.invoice.note', ['base' => number_format($base, 2, '.', ''), 'type' => $documentType]).($command->notes !== null ? ' '.$command->notes : ''),
            'items' => $items,
            'created_by' => $command->userId,
        ], $items);
    }

    /**
     * @throws BondedWarehouseException self_contact_missing
     */
    private function selfContactId(ExecuteVatDepotExtractionAndEmitSelfInvoiceCommand $command, string $tenantId): string
    {
        if ($command->selfContactId !== null) {
            return (string) (Contact::query()->find($command->selfContactId)?->id ?? throw BondedWarehouseException::because('self_contact_missing'));
        }

        $tenant = Tenant::query()->find($this->tenantContext->getTenantId() ?? $tenantId);
        $vat = $tenant?->vat_number;
        $contact = $vat === null || $vat === '' ? null : Contact::query()->where('vat_number', $vat)->first();

        return (string) ($contact?->id ?? throw BondedWarehouseException::because('self_contact_missing'));
    }
}
