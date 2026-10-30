<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Scopes;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

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
        'payment_term_translations',
        'unit_of_measures',
        'vat_codes',
        'vat_code_translations',
        'countries',
        'country_translations',
        'currencies',
        'currency_translations',
        'regions',
        'region_translations',
        'document_statuses',
        'document_status_translations',
        'inventory_movement_types',
        'inventory_movement_type_translations',
        'expense_categories',
        'expense_category_translations',
        'contact_categories',
        'contact_category_translations',
        'contact_types',
        'contact_type_translations',
        'project_statuses',
        'project_status_translations',
        'task_statuses',
        'task_status_translations',
        'workflow_states',
        'workflow_state_translations',
        'approval_statuses',
        'approval_status_translations',
        'notification_types',
        'notification_type_translations',
        'payment_methods',
        'payment_method_translations',
    ];

    public function apply(Builder $builder, Model $model): void
    {
        $tenantContext = app(TenantContext::class);
        $column = $model->qualifyColumn('tenant_id');

        $isShared = method_exists($model, 'isSharedLookup')
            ? (bool) $model->isSharedLookup()
            : in_array($model->getTable(), $this->sharedLookupTables, true);

        if ($isShared && ! Schema::hasColumn($model->getTable(), 'tenant_id')) {
            return;
        }

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
            // Fail-closed behavior: operational records must never be returned without a tenant context
            if ($isShared) {
                $builder->whereNull($column);
            } else {
                $builder->whereRaw('1 = 0');
            }
        }
    }
}
