<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RtFiscalReceipt extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'pos_sale_id',
        'z_report_number',
        'fiscal_document_number',
        'xml_lot_id',
        'rt_status',
        'transmitted_at',
    ];

    protected function casts(): array
    {
        return [
            'transmitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PosSale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }
}
