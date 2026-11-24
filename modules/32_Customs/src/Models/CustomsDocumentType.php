<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

final class CustomsDocumentType extends CustomsMasterRecord
{
    use AllowsGlobalRecords;

    protected $table = 'customs_document_types';
}
