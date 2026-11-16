<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LegalCase extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'legal_cases';

    protected $fillable = [
        'tenant_id',
        'client_id',
        'case_number',
        'title',
        'description',
        'aml_kyc_completed',
        'status',
        'jurisdiction',
    ];

    /**
     * Calculated columns of the grid: the logged time and the fees of the case, summed over its billable activities.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'logged_minutes' => 'COALESCE((SELECT SUM(billable_activities.duration_minutes) FROM billable_activities WHERE billable_activities.legal_case_id = legal_cases.id), 0)',
            'fee_total' => 'COALESCE((SELECT SUM(billable_activities.fee_amount) FROM billable_activities WHERE billable_activities.legal_case_id = legal_cases.id), 0)',
        ];
    }

    protected function casts(): array
    {
        return [
            'aml_kyc_completed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'client_id');
    }

    /**
     * @return HasMany<BillableActivity, $this>
     */
    public function billableActivities(): HasMany
    {
        return $this->hasMany(BillableActivity::class, 'legal_case_id');
    }

    /**
     * @return HasMany<FeeNote, $this>
     */
    public function feeNotes(): HasMany
    {
        return $this->hasMany(FeeNote::class, 'legal_case_id');
    }
}
