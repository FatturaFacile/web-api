<?php

declare(strict_types=1);

namespace Modules\Esterometro\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail of SDI transmission events, receipts (RC), rejections (NS), and manual overrides.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $self_invoice_id
 * @property string $event_type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $sdi_identificativo
 * @property string|null $sdi_filename
 * @property string|null $error_code
 * @property string|null $error_description
 * @property string|null $raw_payload
 * @property string|null $created_by
 */
final class SelfInvoiceSdiLog extends BaseEnterpriseModel
{
    protected $table = 'self_invoice_sdi_logs';

    protected $fillable = [
        'tenant_id',
        'self_invoice_id',
        'event_type',
        'from_status',
        'to_status',
        'sdi_identificativo',
        'sdi_filename',
        'error_code',
        'error_description',
        'raw_payload',
        'created_by',
    ];

    /**
     * @return BelongsTo<SelfInvoice, $this>
     */
    public function selfInvoice(): BelongsTo
    {
        return $this->belongsTo(SelfInvoice::class, 'self_invoice_id');
    }
}
