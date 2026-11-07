<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DailyCorrispettivo extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'daily_corrispettivi';

    protected $fillable = [
        'tenant_id', 'business_date', 'tax_rate', 'vat_nature', 'sales_net', 'sales_tax', 'sales_gross', 'voided_gross',
        'returned_gross', 'total_net', 'total_tax', 'total_gross', 'document_count', 'gateway', 'gateway_status',
        'gateway_reference', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'tax_rate' => 'decimal:2',
            'sales_net' => 'decimal:2',
            'sales_tax' => 'decimal:2',
            'sales_gross' => 'decimal:2',
            'voided_gross' => 'decimal:2',
            'returned_gross' => 'decimal:2',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
            'document_count' => 'integer',
            'closed_at' => 'datetime',
        ];
    }
}
