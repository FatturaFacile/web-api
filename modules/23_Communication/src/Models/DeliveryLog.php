<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DeliveryLog extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'channel', 'driver', 'document_type', 'document_id', 'recipient', 'status', 'provider_reference', 'error', 'sent_by'];
}
