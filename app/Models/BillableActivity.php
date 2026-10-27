<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillableActivity extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'billable_activities';

    protected $fillable = [
        'tenant_id',
        'legal_case_id',
        'user_id',
        'date',
        'duration_minutes',
        'hourly_rate',
        'fee_amount',
        'description',
        'is_billed',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'duration_minutes' => 'integer',
            'hourly_rate' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'is_billed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<LegalCase, $this>
     */
    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'legal_case_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
