<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use App\Models\Contact;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\ServiceManagement\Contracts\EquipmentPassportServiceInterface;
use Modules\ServiceManagement\DTOs\AddWarrantyExtensionDTO;
use Modules\ServiceManagement\DTOs\RegisterEquipmentPassportDTO;
use Modules\ServiceManagement\DTOs\UpdateEquipmentPassportDTO;
use Modules\ServiceManagement\Events\EquipmentPassportRegisteredEvent;
use Modules\ServiceManagement\Exceptions\WarrantyRmaException;
use Modules\ServiceManagement\Models\EquipmentComponent;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\WarrantyExtension;
use Modules\ServiceManagement\Warranty\ExtendedCarePackExtension;
use Modules\ServiceManagement\Warranty\WarrantyCalculator;
use Modules\ServiceManagement\Warranty\WarrantyContext;
use Modules\ServiceManagement\Warranty\WarrantyResult;

final readonly class EquipmentPassportService implements EquipmentPassportServiceInterface
{
    /**
     * Consumer protection floor (CH Art. 210 CO / IT Codice del Consumo): B2C warranty may not go below 24 months.
     */
    public const B2C_MINIMUM_MONTHS = 24;

    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private WarrantyCalculator $calculator,
    ) {}

    public function register(RegisterEquipmentPassportDTO $dto, ?string $actorUserId = null): SerializedEquipmentPassport
    {
        $customer = $this->resolveCustomer($dto->currentOwnerCustomerId);
        $this->assertStockSerialBelongsToTenant($dto->stockSerialNumberId, 'stock_serial_number_id');

        $duplicate = SerializedEquipmentPassport::query()
            ->where('serial_number', $dto->serialNumber)
            ->when(
                $dto->productId !== null,
                fn ($query) => $query->where('product_id', $dto->productId),
                fn ($query) => $query->whereNull('product_id'),
            )
            ->exists();

        if ($duplicate) {
            throw WarrantyRmaException::validation('serial_number', 'An equipment passport already exists for this serial number.', 'passport_exists');
        }

        $customerType = $dto->customerType ?? $this->deriveCustomerType($customer);
        $jurisdiction = $dto->jurisdiction ?? $this->deriveJurisdiction($customer);
        $this->assertContractMonthsAllowed($customerType, $dto->contractWarrantyMonths);

        $result = $this->calculate(
            jurisdiction: $jurisdiction,
            customerType: $customerType,
            deliveryDate: CarbonImmutable::parse($dto->deliveryDate)->startOfDay(),
            contractMonths: $dto->contractWarrantyMonths,
        );

        return DB::transaction(function () use ($dto, $customerType, $jurisdiction, $result): SerializedEquipmentPassport {
            $passport = SerializedEquipmentPassport::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'stock_serial_number_id' => $dto->stockSerialNumberId,
                'product_id' => $dto->productId,
                'serial_number' => $dto->serialNumber,
                'current_owner_customer_id' => $dto->currentOwnerCustomerId,
                'original_sales_invoice_id' => $dto->originalSalesInvoiceId,
                'customer_type' => $customerType,
                'jurisdiction' => $jurisdiction,
                'delivery_date' => $result->startDate->toDateString(),
                'warranty_policy_type' => $result->policyType->value,
                'warranty_start_date' => $result->startDate->toDateString(),
                'warranty_expiry_date' => $result->expiryDate->toDateString(),
                'warranty_source' => $result->source,
                'contract_warranty_months' => $dto->contractWarrantyMonths,
                'contract_clause_reference' => $dto->contractClauseReference,
                'installed_firmware_version' => $dto->installedFirmwareVersion,
                'notes' => $dto->notes,
                'asset_status' => $dto->assetStatus,
                'commissioning_date' => $dto->commissioningDate,
                'installation_site' => $dto->installationSite,
            ]);

            foreach ($dto->components as $component) {
                EquipmentComponent::create([
                    'tenant_id' => $passport->tenant_id,
                    'company_id' => $passport->company_id,
                    'equipment_passport_id' => $passport->id,
                    'position' => $component['position'],
                    'component_serial' => $component['component_serial'],
                    'component_product_id' => $component['component_product_id'] ?? null,
                    'role' => $component['role'] ?? null,
                    'source' => EquipmentComponent::SOURCE_AS_BUILT,
                    'installed_at' => $result->startDate,
                ]);
            }

            $this->auditLedger->record(
                'EQUIPMENT_PASSPORT_REGISTERED',
                $passport,
                null,
                ['serial_number' => $passport->serial_number, 'warranty_expiry_date' => $result->expiryDate->toDateString()],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new EquipmentPassportRegisteredEvent(
                passportId: $passport->id,
                serialNumber: $passport->serial_number,
                productId: $passport->product_id,
                customerId: $passport->current_owner_customer_id,
                warrantyPolicyType: $result->policyType->value,
                warrantyExpiryDate: $result->expiryDate->toDateString(),
                tenantId: $passport->tenant_id,
                companyId: $passport->company_id ?? $passport->tenant_id,
            ));

            return $passport->load(['components', 'extensions', 'customer']);
        });
    }

    public function update(SerializedEquipmentPassport $passport, UpdateEquipmentPassportDTO $dto, ?string $actorUserId = null): SerializedEquipmentPassport
    {
        $attributes = $dto->attributes;

        if (array_key_exists('current_owner_customer_id', $attributes)) {
            $this->resolveCustomer((string) $attributes['current_owner_customer_id']);
        }

        $customerType = (string) ($attributes['customer_type'] ?? $passport->customer_type);
        $contractMonths = array_key_exists('contract_warranty_months', $attributes)
            ? ($attributes['contract_warranty_months'] !== null ? (int) $attributes['contract_warranty_months'] : null)
            : $passport->contract_warranty_months;
        $this->assertContractMonthsAllowed($customerType, $contractMonths);

        if (array_key_exists('contract_warranty_months', $attributes)) {
            $attributes['contract_warranty_months'] = $contractMonths;
        }

        return DB::transaction(function () use ($passport, $attributes): SerializedEquipmentPassport {
            $before = $passport->only(['current_owner_customer_id', 'contract_warranty_months', 'installed_firmware_version', 'warranty_expiry_date']);

            $passport->fill($attributes);
            $this->recompute($passport);
            $passport->save();

            $this->auditLedger->record(
                'EQUIPMENT_PASSPORT_UPDATED',
                $passport,
                $before,
                $passport->only(['current_owner_customer_id', 'contract_warranty_months', 'installed_firmware_version', 'warranty_expiry_date']),
                'MODULE_17_ESM'
            );

            return $passport->fresh(['components', 'extensions', 'customer']) ?? $passport;
        });
    }

    public function addExtension(SerializedEquipmentPassport $passport, AddWarrantyExtensionDTO $dto, ?string $actorUserId = null): SerializedEquipmentPassport
    {
        $extension = ExtendedCarePackExtension::fromStart($dto->name, $dto->months, CarbonImmutable::parse($dto->startsAt)->startOfDay());
        $deliveryDate = CarbonImmutable::parse($passport->delivery_date->toDateString());

        if ($extension->endsAt->lessThan($deliveryDate)) {
            throw WarrantyRmaException::validation('starts_at', 'The care pack would end before the delivery date.', 'extension_ends_before_delivery');
        }

        return DB::transaction(function () use ($passport, $dto, $extension): SerializedEquipmentPassport {
            WarrantyExtension::create([
                'tenant_id' => $passport->tenant_id,
                'company_id' => $passport->company_id,
                'equipment_passport_id' => $passport->id,
                'name' => $dto->name,
                'months' => $dto->months,
                'starts_at' => $extension->startsAt->toDateString(),
                'ends_at' => $extension->endsAt->toDateString(),
                'reference' => $dto->reference,
                'price' => $dto->price,
                'currency' => $dto->currency ?? 'EUR',
            ]);

            $this->recompute($passport);
            $passport->save();

            $this->auditLedger->record(
                'EQUIPMENT_WARRANTY_EXTENDED',
                $passport,
                null,
                ['extension' => $dto->name, 'warranty_expiry_date' => $passport->warranty_expiry_date->toDateString()],
                'MODULE_17_ESM'
            );

            return $passport->fresh(['components', 'extensions', 'customer']) ?? $passport;
        });
    }

    public function evaluateWarranty(SerializedEquipmentPassport $passport): WarrantyResult
    {
        return $this->calculate(
            jurisdiction: $passport->jurisdiction,
            customerType: $passport->customer_type,
            deliveryDate: CarbonImmutable::parse($passport->delivery_date->toDateString()),
            contractMonths: $passport->contract_warranty_months,
            extensions: $this->extensionsOf($passport),
        );
    }

    public function findBySerial(string $serialNumber, ?string $productId = null): SerializedEquipmentPassport
    {
        $serialNumber = trim($serialNumber);

        $passport = SerializedEquipmentPassport::query()
            ->where('serial_number', $serialNumber)
            ->when($productId !== null, fn ($query) => $query->where('product_id', $productId))
            ->orderByDesc('delivery_date')
            ->first();

        if ($passport !== null) {
            return $passport;
        }

        $stock = StockSerialNumber::query()
            ->where('serial_number', $serialNumber)
            ->first();

        if ($stock !== null) {
            throw new WarrantyRmaException(
                'The serial number is known to inventory but has no equipment passport yet.',
                'passport_missing',
                404,
                [],
                ['stock_hint' => [
                    'stock_serial_number_id' => $stock->id,
                    'stock_item_id' => $stock->stock_item_id,
                    'serial_number' => $stock->serial_number,
                    'status' => $stock->status,
                    'warranty_start_date' => $stock->warranty_start_date?->toDateString(),
                    'warranty_end_date' => $stock->warranty_end_date?->toDateString(),
                ]],
            );
        }

        throw WarrantyRmaException::notFound('serial_not_found', 'Serial number not found.');
    }

    public function lookup(string $serialNumber, ?string $productId = null, ?CarbonInterface $at = null): array
    {
        $passport = $this->findBySerial($serialNumber, $productId);
        $passport->loadMissing(['components', 'extensions', 'customer']);

        $warranty = $this->evaluateWarranty($passport)->evaluationAt($at ?? now());

        $history = $passport->rmaOrders()
            ->orderByDesc('created_at')
            ->get(['id', 'rma_number', 'status', 'created_at'])
            ->map(static fn ($rma): array => [
                'id' => $rma->id,
                'rma_number' => $rma->rma_number,
                'status' => $rma->status,
                'created_at' => $rma->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'passport' => $passport,
            'warranty' => $warranty,
            'rma_history' => $history,
            'stock_hint' => null,
        ];
    }

    public function genealogy(SerializedEquipmentPassport $passport): array
    {
        $components = $passport->components()->orderBy('installed_at')->orderBy('created_at')->get();

        $asBuilt = $components->where('source', EquipmentComponent::SOURCE_AS_BUILT)->values()->all();
        $asMaintained = $components->whereNull('removed_at')->values()->all();
        $byId = $components->keyBy('id');

        $history = $components
            ->whereNotNull('removed_at')
            ->map(static function (EquipmentComponent $removed) use ($byId): array {
                $replacement = $removed->replaced_by_component_id !== null ? $byId->get($removed->replaced_by_component_id) : null;

                return [
                    'rma_order_id' => $replacement !== null ? ($replacement->rma_order_id ?? $removed->rma_order_id) : $removed->rma_order_id,
                    'replaced_component_id' => $removed->id,
                    'new_component_id' => $removed->replaced_by_component_id,
                    'at' => $removed->removed_at?->toIso8601String(),
                ];
            })
            ->sortBy('at')
            ->values()
            ->all();

        return [
            'as_built' => $asBuilt,
            'as_maintained' => $asMaintained,
            'history' => $history,
        ];
    }

    /**
     * Recompute and assign the persisted warranty fields (no save).
     */
    private function recompute(SerializedEquipmentPassport $passport): void
    {
        $result = $this->evaluateWarranty($passport);

        $passport->warranty_policy_type = $result->policyType->value;
        $passport->warranty_start_date = $result->startDate;
        $passport->warranty_expiry_date = $result->expiryDate;
        $passport->warranty_source = $result->source;
    }

    /**
     * @param  list<ExtendedCarePackExtension>  $extensions
     */
    private function calculate(
        string $jurisdiction,
        string $customerType,
        CarbonImmutable $deliveryDate,
        ?int $contractMonths,
        array $extensions = [],
    ): WarrantyResult {
        try {
            return $this->calculator->calculate(new WarrantyContext(
                jurisdiction: $jurisdiction,
                customerType: $customerType,
                deliveryDate: $deliveryDate,
                contractWarrantyMonths: $contractMonths,
                extensions: $extensions,
            ));
        } catch (InvalidArgumentException $exception) {
            throw WarrantyRmaException::validation('jurisdiction', $exception->getMessage(), 'warranty_rules_unavailable');
        }
    }

    /**
     * @return list<ExtendedCarePackExtension>
     */
    private function extensionsOf(SerializedEquipmentPassport $passport): array
    {
        if (! $passport->exists) {
            return [];
        }

        return $passport->extensions()
            ->get()
            ->map(static fn (WarrantyExtension $extension): ExtendedCarePackExtension => new ExtendedCarePackExtension(
                name: $extension->name,
                months: $extension->months,
                startsAt: CarbonImmutable::parse($extension->starts_at->toDateString()),
                endsAt: CarbonImmutable::parse($extension->ends_at->toDateString()),
            ))
            ->values()
            ->all();
    }

    private function resolveCustomer(string $customerId): Contact
    {
        $customer = Contact::query()->whereKey($customerId)->first();

        if ($customer === null) {
            throw WarrantyRmaException::validation('current_owner_customer_id', 'The selected customer does not exist.', 'customer_not_found');
        }

        return $customer;
    }

    private function assertStockSerialBelongsToTenant(?string $stockSerialNumberId, string $field): void
    {
        if ($stockSerialNumberId === null) {
            return;
        }

        if (! StockSerialNumber::query()->whereKey($stockSerialNumberId)->exists()) {
            throw WarrantyRmaException::validation($field, 'The selected stock serial number does not exist.', 'stock_serial_not_found');
        }
    }

    private function assertContractMonthsAllowed(string $customerType, ?int $contractMonths): void
    {
        if ($contractMonths !== null && $customerType === WarrantyContext::CUSTOMER_TYPE_B2C && $contractMonths < self::B2C_MINIMUM_MONTHS) {
            throw WarrantyRmaException::validation(
                'contract_warranty_months',
                'Consumer (B2C) warranty cannot be shorter than 24 months.',
                'b2c_minimum_warranty',
            );
        }
    }

    private function deriveCustomerType(Contact $customer): string
    {
        return trim((string) ($customer->vat_number ?? '')) !== ''
            ? WarrantyContext::CUSTOMER_TYPE_B2B
            : WarrantyContext::CUSTOMER_TYPE_B2C;
    }

    private function deriveJurisdiction(Contact $customer): string
    {
        $country = strtoupper((string) ($customer->country ?? ''));

        return in_array($country, ['CH', 'IT'], true) ? $country : 'IT';
    }
}
