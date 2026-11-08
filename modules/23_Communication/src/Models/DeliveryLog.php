<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\DeliveryLogFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model of the delivery log (tenant scoped where it has a tenant_id).
 */
#[UseFactory(DeliveryLogFactory::class)]
class DeliveryLog extends Model
{
    /** @use HasFactory<DeliveryLogFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'channel', 'driver', 'document_type', 'document_id', 'recipient', 'status', 'provider_reference', 'error', 'sent_by'];
}
