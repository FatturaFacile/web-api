<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentImport extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'file_name',
        'format',
        'total_entries',
        'matched_entries',
        'duplicate_entries',
        'total_fees',
    ];

    protected function casts(): array
    {
        return [
            'duplicate_entries' => 'integer',
            'total_fees' => 'decimal:2',
            'matched_entries' => 'integer',
            'total_entries' => 'integer',
        ];
    }

    /**
     * @return HasMany<PaymentImportLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PaymentImportLine::class);
    }
}
