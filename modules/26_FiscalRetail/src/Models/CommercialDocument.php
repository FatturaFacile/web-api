<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommercialDocument extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'pos_sale_id', 'related_document_id', 'document_type', 'fiscal_year', 'sequential_number',
        'document_number', 'issue_date', 'payment_method', 'total_net', 'total_tax', 'total_gross', 'gateway',
        'gateway_status', 'gateway_reference',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'fiscal_year' => 'integer',
            'sequential_number' => 'integer',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<CommercialDocumentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CommercialDocumentLine::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function relatedDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_document_id');
    }
}
