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

    /**
     * Accepted by a real RT service and sent on to the Agenzia delle Entrate.
     */
    public const StatusTransmitted = 'transmitted';

    /**
     * Produced by the offline stand-in printer: the identifiers are local and nothing reached the tax authority.
     */
    public const StatusSimulated = 'simulated';

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

    public function isSimulated(): bool
    {
        return $this->rt_status === self::StatusSimulated;
    }

    /**
     * True only when the receipt was really handed to the Agenzia delle Entrate.
     */
    public function reachedTaxAuthority(): bool
    {
        return $this->rt_status === self::StatusTransmitted && $this->transmitted_at !== null;
    }

    /**
     * @return BelongsTo<PosSale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }
}
