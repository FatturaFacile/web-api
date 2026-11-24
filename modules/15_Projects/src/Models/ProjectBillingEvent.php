<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * A billing run of the project (a milestone or a time and materials period) and the draft invoice it produced.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property string $event_type
 * @property string|null $source_reference_id
 * @property string $billing_amount
 * @property string $status
 * @property string|null $invoice_id
 */
final class ProjectBillingEvent extends BaseEnterpriseModel
{
    protected $table = 'project_billing_events';

    protected function casts(): array
    {
        return [
            'billing_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }
}
