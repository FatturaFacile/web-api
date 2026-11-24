<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentBatch extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'payment_batches';

    protected $fillable = [
        'tenant_id',
        'format',
        'status',
        'file_path',
        'total_amount',
        'currency',
        'execution_date',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'execution_date' => 'date',
        ];
    }
}
