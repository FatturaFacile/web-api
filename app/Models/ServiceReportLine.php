<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceReportLine extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'service_report_lines';

    protected $fillable = [
        'tenant_id',
        'service_report_id',
        'line_type_id',
        'item_id',
        'description',
        'quantity',
        'unit_of_measure',
        'unit_price',
        'discount_percent',
        'vat_code_id',
        'tax_rate',
        'line_net',
        'line_tax',
        'line_total',
        'is_billable',
        'notes',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_net' => 'decimal:4',
            'line_tax' => 'decimal:4',
            'line_total' => 'decimal:4',
            'is_billable' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ServiceReport, $this>
     */
    public function serviceReport(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class, 'service_report_id');
    }

    /**
     * @return BelongsTo<LookupItem, $this>
     */
    public function lineType(): BelongsTo
    {
        return $this->belongsTo(LookupItem::class, 'line_type_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'item_id');
    }

    /**
     * @return BelongsTo<VatCode, $this>
     */
    public function vatCode(): BelongsTo
    {
        return $this->belongsTo(VatCode::class, 'vat_code_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
