<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GeneralLedgerEntry extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'general_ledger_entries';

    protected $fillable = [
        'tenant_id',
        'entry_number',
        'date',
        'description',
        'reference_type',
        'reference_id',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * @return HasMany<GeneralLedgerLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GeneralLedgerLine::class, 'general_ledger_entry_id');
    }
}
