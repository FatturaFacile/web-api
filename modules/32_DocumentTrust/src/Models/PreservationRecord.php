<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PreservationRecord extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'document_type', 'document_id', 'provider', 'status', 'reference', 'content_sha256'];
}
