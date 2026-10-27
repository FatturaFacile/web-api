<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VatSettlement extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'vat_settlements';

    protected $fillable = [
        'tenant_id',
        'period_type',
        'year',
        'period',
        'total_debit_vat',
        'total_credit_vat',
        'payable_amount',
        'is_paid',
        'payment_date',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'period' => 'integer',
            'total_debit_vat' => 'decimal:2',
            'total_credit_vat' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'is_paid' => 'boolean',
            'payment_date' => 'date',
        ];
    }
}
