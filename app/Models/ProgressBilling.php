<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProgressBilling extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'progress_billings';

    protected $fillable = [
        'tenant_id',
        'construction_site_id',
        'sal_number',
        'period_start',
        'period_end',
        'gross_certified_amount',
        'retention_amount',
        'net_payable_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_certified_amount' => 'decimal:2',
            'retention_amount' => 'decimal:2',
            'net_payable_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ConstructionSite, $this>
     */
    public function constructionSite(): BelongsTo
    {
        return $this->belongsTo(ConstructionSite::class, 'construction_site_id');
    }
}
