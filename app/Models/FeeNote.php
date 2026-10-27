<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeeNote extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'fee_notes';

    protected $fillable = [
        'tenant_id',
        'legal_case_id',
        'client_id',
        'note_number',
        'date',
        'taxable_fees',
        'cassa_previdenza_amount',
        'ritenuta_acconto_amount',
        'vat_amount',
        'net_payable',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'taxable_fees' => 'decimal:2',
            'cassa_previdenza_amount' => 'decimal:2',
            'ritenuta_acconto_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',
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
     * @return BelongsTo<Contact, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'client_id');
    }
}
