<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Eloquent model of the contract alert (tenant scoped where it has a tenant_id).
 */
final class ContractAlert extends BaseEnterpriseModel
{
    protected $table = 'contract_alerts';

    /**
     * Calculated columns of the grid: the number and title of the contract the alert belongs to (customer or supplier).
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        $pick = static fn (string $column): string => "CASE WHEN contract_alerts.contract_kind = 'customer'"
            ." THEN (SELECT esm_service_contracts.{$column} FROM esm_service_contracts WHERE esm_service_contracts.id = contract_alerts.contract_id)"
            ." ELSE (SELECT supplier_contracts.{$column} FROM supplier_contracts WHERE supplier_contracts.id = contract_alerts.contract_id) END";

        return [
            'contract_number' => $pick('contract_number'),
            'contract_title' => $pick('title'),
        ];
    }

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'acknowledged_at' => 'datetime',
        ];
    }
}
