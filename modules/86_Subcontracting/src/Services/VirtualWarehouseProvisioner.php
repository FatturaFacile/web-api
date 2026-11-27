<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Services;

use App\Models\Contact;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\Supplier;
use Modules\Subcontracting\Domain\Enums\Direction;
use Modules\Subcontracting\Exceptions\SubcontractingException;

/**
 * The virtual warehouse of a partner (Mod 051). Passive: the vendor's warehouse, still company-owned stock held outside our
 * premises. Active: the segregated customer-goods warehouse, legally owned by the customer, so it never counts as a company asset
 * (the inventory valuation report puts every warehouse not owned by the company in the excluded third-party bucket).
 */
final class VirtualWarehouseProvisioner
{
    /**
     * The partner's virtual warehouse, created when missing.
     *
     * @return array{warehouse: Warehouse, partner_name: string}
     */
    public function provision(Direction $direction, string $partnerId, string $tenantId): array
    {
        [$partnerCode, $partnerName] = $this->partner($direction, $partnerId, $tenantId);

        $warehouse = Warehouse::query()
            ->where('tenant_id', $tenantId)
            ->where('linked_partner_id', $partnerId)
            ->where('warehouse_type', $this->warehouseType($direction))
            ->where('legal_owner_type', $direction->isCustomerOwned() ? 'customer' : 'company')
            ->where('is_virtual', true)
            ->first();

        if (! $warehouse instanceof Warehouse) {
            $warehouse = Warehouse::create([
                'tenant_id' => $tenantId,
                'code' => $this->uniqueCode($direction, $partnerCode, $tenantId),
                'name' => ($direction->isCustomerOwned() ? 'Merci cliente – ' : 'Terzista – ').$partnerName,
                'warehouse_type' => $this->warehouseType($direction),
                'legal_owner_type' => $direction->isCustomerOwned() ? 'customer' : 'company',
                'linked_partner_id' => $partnerId,
                'is_virtual' => true,
                'is_primary' => false,
                'is_active' => true,
            ]);
        }

        return ['warehouse' => $warehouse, 'partner_name' => $partnerName];
    }

    /**
     * The partner exists in the active company.
     *
     * @return array{0: string, 1: string} code and display name
     */
    public function partner(Direction $direction, string $partnerId, string $tenantId): array
    {
        if ($direction === Direction::Passive) {
            $supplier = Supplier::query()->where('tenant_id', $tenantId)->whereKey($partnerId)->first();

            if (! $supplier instanceof Supplier) {
                throw new SubcontractingException('partner_not_found');
            }

            return [(string) $supplier->supplier_code, (string) $supplier->company_name];
        }

        $contact = Contact::query()->where('tenant_id', $tenantId)->whereKey($partnerId)->first();

        if (! $contact instanceof Contact) {
            throw new SubcontractingException('partner_not_found');
        }

        $name = (string) ($contact->getAttribute('company_name') ?? $contact->getAttribute('name') ?? $partnerId);

        return [substr(str_replace('-', '', (string) $contact->getKey()), 0, 8), $name];
    }

    private function warehouseType(Direction $direction): string
    {
        return $direction === Direction::Passive ? 'subcontractor' : 'standard';
    }

    private function uniqueCode(Direction $direction, string $partnerCode, string $tenantId): string
    {
        $slug = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $partnerCode));
        $base = ($direction === Direction::Passive ? 'SCT-' : 'CGW-').substr($slug !== '' ? $slug : 'PARTNER', 0, 30);
        $code = $base;
        $n = 1;

        while (Warehouse::withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
            $code = $base.'-'.(++$n);
        }

        return $code;
    }
}
