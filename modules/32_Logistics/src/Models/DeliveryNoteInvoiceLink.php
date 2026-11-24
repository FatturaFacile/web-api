<?php

declare(strict_types=1);

namespace Modules\Logistics\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\Logistics\Concerns\HasAuditUsers;

final class DeliveryNoteInvoiceLink extends BaseEnterpriseModel
{
    use HasAuditUsers;

    protected $table = 'delivery_note_invoice_links';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }
}
