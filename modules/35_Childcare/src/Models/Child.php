<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A child enrolled in the kindergarten. Names, birth date, health data and the inputs of the subsidy calculation are
 * encrypted; documents shared outside the module (invoices) use the reference code only.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $reference_code
 * @property ?string $group_id
 * @property string $billing_contact_id
 * @property string $first_name
 * @property string $last_name
 * @property ?string $birth_date
 * @property ?string $allergies
 * @property ?string $dietary_notes
 * @property ?string $health_notes
 * @property ?array<string, mixed> $subsidy_inputs
 * @property ?string $subsidy_canton
 * @property CarbonImmutable $enrolled_on
 * @property ?CarbonImmutable $left_on
 * @property string $status
 */
final class Child extends ChildcareRecord
{
    protected $table = 'cc_children';

    protected function casts(): array
    {
        return [
            'first_name' => 'encrypted',
            'last_name' => 'encrypted',
            'birth_date' => 'encrypted',
            'allergies' => 'encrypted',
            'dietary_notes' => 'encrypted',
            'health_notes' => 'encrypted',
            'subsidy_inputs' => 'encrypted:array',
            'enrolled_on' => 'immutable_date',
            'left_on' => 'immutable_date',
        ];
    }

    /**
     * Whether the child is enrolled on the given day (between enrolment and leaving dates).
     */
    public function isEnrolledOn(CarbonImmutable $date): bool
    {
        return $this->enrolled_on->lte($date) && ($this->left_on === null || $this->left_on->gte($date));
    }

    /**
     * @return HasMany<ReferencePerson, $this>
     */
    public function referencePersons(): HasMany
    {
        return $this->hasMany(ReferencePerson::class, 'child_id');
    }

    /**
     * @return HasMany<WeeklyPlan, $this>
     */
    public function weeklyPlans(): HasMany
    {
        return $this->hasMany(WeeklyPlan::class, 'child_id')->orderByDesc('valid_from');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function billingContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'billing_contact_id');
    }
}
