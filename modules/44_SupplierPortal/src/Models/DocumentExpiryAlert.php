<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Marks that the alert of a document for a threshold (days before expiry, 0 = expired) was already sent, so the daily
 * command never alerts twice for the same step.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $submission_id
 * @property int $threshold_days
 * @property Carbon $sent_at
 */
final class DocumentExpiryAlert extends BaseEnterpriseModel
{
    protected $table = 'sp_document_alerts';

    protected function casts(): array
    {
        return ['threshold_days' => 'integer', 'sent_at' => 'datetime'];
    }
}
