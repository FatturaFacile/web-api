<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\BondedWarehouses\Domain\Enums\AeoStatus;
use Modules\BondedWarehouses\Domain\Enums\AuditType;
use Modules\BondedWarehouses\Domain\Enums\DiscrepancyStatus;

/**
 * Internal control log (Art. 39 CDU): WMS vs customs book reconciliation, supply chain security checks, usual handling verification and CGU thresholds.
 *
 * @property string $authorization_id
 * @property AuditType $audit_type
 * @property Carbon $audit_date
 * @property string|null $mrn_lot_id
 * @property string|null $product_id
 * @property string $wms_physical_quantity
 * @property string $customs_ledger_quantity
 * @property string $discrepancy_quantity
 * @property DiscrepancyStatus $discrepancy_status
 * @property string|null $partner_or_carrier_name
 * @property AeoStatus|null $partner_aeo_status
 * @property bool $security_seal_verified
 * @property string|null $findings
 * @property string|null $corrective_action
 * @property string|null $auditor_user_id
 */
final class AeoComplianceAudit extends BaseEnterpriseModel
{
    protected $table = 'aeo_compliance_audits';

    protected function casts(): array
    {
        return [
            'audit_type' => AuditType::class,
            'discrepancy_status' => DiscrepancyStatus::class,
            'partner_aeo_status' => AeoStatus::class,
            'audit_date' => 'date',
            'security_seal_verified' => 'boolean',
            'wms_physical_quantity' => 'decimal:4',
            'customs_ledger_quantity' => 'decimal:4',
            'discrepancy_quantity' => 'decimal:4',
        ];
    }
}
