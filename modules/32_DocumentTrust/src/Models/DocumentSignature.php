<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentSignature extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'document_type', 'document_id', 'driver', 'format', 'content_sha256', 'signature'];
}
