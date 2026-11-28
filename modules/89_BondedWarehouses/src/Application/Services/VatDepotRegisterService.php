<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Services;

use Illuminate\Support\Carbon;
use Modules\BondedWarehouses\Domain\Enums\RegimeType;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;
use Modules\BondedWarehouses\Models\VatDepotRegisterEntry;

/**
 * Progressive numbering and stock balance of the Art. 50-bis load / unload register.
 */
final class VatDepotRegisterService
{
    /**
     * Locks and returns the IVA warehouse authorization (the lock serializes the progressive numbers).
     *
     * @throws BondedWarehouseException authorization_not_found, authorization_inactive, regime_not_vat_depot
     */
    public function lockDepot(string $authorizationId, string $date): CustomsWarehouseAuthorization
    {
        $authorization = CustomsWarehouseAuthorization::query()->whereKey($authorizationId)->lockForUpdate()->first()
            ?? throw BondedWarehouseException::because('authorization_not_found', [], 404);

        if ($authorization->regime_type !== RegimeType::VatDepotArt50Bis) {
            throw BondedWarehouseException::because('regime_not_vat_depot');
        }

        if (! $authorization->isUsableOn(Carbon::parse($date))) {
            throw BondedWarehouseException::because('authorization_inactive', ['code' => $authorization->authorization_code]);
        }

        return $authorization;
    }

    public function nextProgressive(string $authorizationId): int
    {
        return (int) VatDepotRegisterEntry::query()->withTrashed()->where('authorization_id', $authorizationId)->max('register_progressive_number') + 1;
    }

    /**
     * Quantity of a product (and lot) currently held in the warehouse according to the register: loads minus extractions.
     */
    public function balance(string $authorizationId, string $productId, ?string $lotReference): float
    {
        $rows = VatDepotRegisterEntry::query()
            ->where('authorization_id', $authorizationId)
            ->where('product_id', $productId)
            ->when($lotReference === null, static fn ($query) => $query->whereNull('lot_reference'), static fn ($query) => $query->where('lot_reference', $lotReference))
            ->get(['movement_type', 'quantity']);

        $balance = 0.0;

        foreach ($rows as $row) {
            $quantity = (float) $row->quantity;

            if ($row->isInbound()) {
                $balance += $quantity;
            } elseif ($row->isExtraction()) {
                $balance -= $quantity;
            }
        }

        return round($balance, 4);
    }
}
