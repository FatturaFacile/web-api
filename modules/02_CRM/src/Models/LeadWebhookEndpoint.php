<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LeadWebhookEndpoint extends BaseEnterpriseModel
{
    protected $table = 'crm_lead_webhook_endpoints';

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'field_map' => 'array',
            'is_active' => 'boolean',
            'last_received_at' => 'datetime',
        ];
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }
}
