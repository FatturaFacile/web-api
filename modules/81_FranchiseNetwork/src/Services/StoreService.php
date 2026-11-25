<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use App\Models\Contact;
use App\Models\Tenant;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FranchiseNetwork\Domain\Enums\StoreType;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Models\FranchiseStore;

/**
 * Stores of the network. A franchisee store belongs to a franchisee company with its own tenant (own books, VAT / UID and ledger):
 * the franchisor only keeps a link to it, set when the franchisee redeems the link code, and a customer record in its own CRM
 * to invoice the royalties. A directly operated store (DOS) has no tenant of its own.
 */
final class StoreService
{
    public function __construct(private readonly NetworkAccess $access) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?FranchiseStore $store, ?string $userId): FranchiseStore
    {
        return DB::transaction(function () use ($data, $store, $userId): FranchiseStore {
            $attributes = collect($data)->only([
                'code', 'name', 'store_type', 'status', 'country', 'city', 'address', 'postal_code', 'size_cluster', 'opened_on',
                'area_manager_user_id', 'vat_number', 'uid_number',
            ])->all();

            if ($store === null) {
                $store = FranchiseStore::query()->create([...$attributes, 'created_by' => $userId]);
            } else {
                $store->update([...$attributes, 'updated_by' => $userId]);
            }

            if ($store->store_type === StoreType::Franchisee) {
                $this->ensureContact($store);
            }

            return $store->refresh();
        });
    }

    public function delete(FranchiseStore $store): void
    {
        if ($store->agreements()->where('status', 'active')->exists()) {
            throw new FranchiseNetworkException('store_has_active_agreement', 409);
        }

        $store->delete();
    }

    /**
     * One-time code the franchisee redeems in its own company to link it to the store.
     */
    public function issueLinkCode(FranchiseStore $store): string
    {
        if ($store->store_type !== StoreType::Franchisee) {
            throw new FranchiseNetworkException('store_not_franchisee', 422);
        }

        $code = strtoupper(Str::random(20));
        $store->update(['link_code' => $code]);

        return $code;
    }

    /**
     * Links the active (franchisee) company to the store that issued the code. The code works once.
     */
    public function redeemLinkCode(string $code): FranchiseStore
    {
        $tenantId = $this->access->tenantId();

        return DB::transaction(function () use ($code, $tenantId): FranchiseStore {
            $store = FranchiseStore::query()->withoutGlobalScope(TenantScope::class)->where('link_code', $code)->lockForUpdate()->first();

            if ($store === null) {
                throw new FranchiseNetworkException('link_code_invalid', 404);
            }

            if ((string) $store->tenant_id === $tenantId) {
                throw new FranchiseNetworkException('link_code_same_company', 422);
            }

            $tenant = Tenant::query()->findOrFail($tenantId);
            $store->forceFill([
                'franchisee_tenant_id' => $tenantId,
                'link_code' => null,
                'vat_number' => $store->vat_number ?: $tenant->vat_number,
                'uid_number' => $store->uid_number ?: $tenant->uid_number,
            ])->save();

            return $store->refresh();
        });
    }

    /**
     * Every franchisee store needs a customer record in the franchisor CRM: the royalty invoices are issued to it.
     */
    private function ensureContact(FranchiseStore $store): void
    {
        if ($store->contact_id !== null) {
            $contact = Contact::query()->find($store->contact_id);

            if ($contact !== null) {
                $contact->update(array_filter(['vat_number' => $store->vat_number, 'uid_number' => $store->uid_number], static fn ($value) => $value !== null));
            }

            return;
        }

        $contact = Contact::query()->create([
            'type' => 'customer',
            'country' => $store->country,
            'company_name' => $store->name,
            'vat_number' => $store->vat_number,
            'uid_number' => $store->uid_number,
        ]);

        $store->update(['contact_id' => $contact->id]);
    }
}
