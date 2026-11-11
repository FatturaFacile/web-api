<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

final class CustomsDocumentType extends CustomsMasterRecord
{
    use AllowsGlobalRecords, BelongsToTenant;

    protected $table = 'customs_document_types';
}
