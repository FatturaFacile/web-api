<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IntercompanyDocument extends BaseEnterpriseModel
{
    protected $table = 'intercompany_documents';

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'net_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'gross_amount' => 'decimal:4',
            'lines' => 'array',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(IntercompanyTransaction::class, 'transaction_id');
    }
}
