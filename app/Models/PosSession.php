<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSession extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'pos_terminal_id',
        'user_id',
        'opened_at',
        'closed_at',
        'opening_float',
        'closing_cash',
        'total_sales',
        'status',
        'expected_cash',
        'cash_difference',
    ];

    protected function casts(): array
    {
        return [
            'cash_difference' => 'decimal:2',
            'closed_at' => 'datetime',
            'closing_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'opened_at' => 'datetime',
            'opening_float' => 'decimal:2',
            'total_sales' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PosTerminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'pos_terminal_id');
    }

    /**
     * @return HasMany<PosSale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }
}
