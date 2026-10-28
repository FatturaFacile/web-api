<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Tables that hold system-wide reference lookup data that may be shared across tenants when tenant_id is null.
     *
     * @var list<string>
     */
    protected array $sharedLookupTables = [
        'lookup_items',
        'lookup_categories',
        'lookup_item_translations',
        'payment_terms',
        'unit_of_measures',
        'vat_codes',
        'countries',
        'currencies',
        'regions',
        'document_statuses',
    ];

    public function apply(Builder $builder, Model $model): void
    {
        $tenantContext = app(TenantContext::class);
        $column = $model->qualifyColumn('tenant_id');

        $isShared = method_exists($model, 'isSharedLookup')
            ? (bool) $model->isSharedLookup()
            : in_array($model->getTable(), $this->sharedLookupTables, true);

        if ($tenantContext->hasTenant()) {
            $tenantId = $tenantContext->getTenantId();

            if ($isShared) {
                $builder->where(function (Builder $query) use ($column, $tenantId): void {
                    $query->where($column, $tenantId)
                        ->orWhereNull($column);
                });
            } else {
                $builder->where($column, $tenantId);
            }
        } else {
            // When no tenant context is active
            if ($isShared) {
                // Shared system lookup items remain accessible
                $builder->whereNull($column);
            } else {
                // Operational records (Invoices, Quotes, Contacts, Tickets, etc.) MUST NEVER be returned without a tenant context
                $builder->whereRaw('1 = 0');
            }
        }
    }
}
