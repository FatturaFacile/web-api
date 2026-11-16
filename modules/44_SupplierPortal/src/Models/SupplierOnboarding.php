<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Procurement\Models\Supplier;
use Modules\SupplierPortal\Enums\OnboardingStatus;

/**
 * The onboarding of a supplier of the procurement module: invitation, registration data sent from the portal, review
 * and decision. One per supplier.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_id
 * @property string $status
 * @property string $country
 * @property string|null $invitation_email
 * @property Carbon|null $invited_at
 * @property string|null $invited_by
 * @property array<string, mixed>|null $registration
 * @property Carbon|null $registration_submitted_at
 * @property Carbon|null $documents_submitted_at
 * @property Carbon|null $review_started_at
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_notes
 * @property string|null $status_reason
 * @property Supplier|null $supplier
 */
final class SupplierOnboarding extends BaseEnterpriseModel
{
    protected $table = 'sp_onboardings';

    protected function casts(): array
    {
        return [
            'registration' => 'array',
            'invited_at' => 'datetime',
            'registration_submitted_at' => 'datetime',
            'documents_submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function statusEnum(): OnboardingStatus
    {
        return OnboardingStatus::from((string) $this->status);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return HasMany<SupplierDocumentSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(SupplierDocumentSubmission::class, 'onboarding_id');
    }
}
