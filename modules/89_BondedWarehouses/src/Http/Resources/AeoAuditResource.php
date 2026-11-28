<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BondedWarehouses\Models\AeoComplianceAudit;

/**
 * @mixin AeoComplianceAudit
 */
final class AeoAuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'authorization_id' => $this->authorization_id,
            'audit_type' => $this->audit_type->value,
            'audit_date' => $this->audit_date->format('Y-m-d'),
            'mrn_lot_id' => $this->mrn_lot_id,
            'product_id' => $this->product_id,
            'wms_physical_quantity' => $this->wms_physical_quantity,
            'customs_ledger_quantity' => $this->customs_ledger_quantity,
            'discrepancy_quantity' => $this->discrepancy_quantity,
            'discrepancy_status' => $this->discrepancy_status->value,
            'partner_or_carrier_name' => $this->partner_or_carrier_name,
            'partner_aeo_status' => $this->partner_aeo_status?->value,
            'security_seal_verified' => $this->security_seal_verified,
            'findings' => $this->findings,
            'corrective_action' => $this->corrective_action,
            'auditor_user_id' => $this->auditor_user_id,
        ];
    }
}
