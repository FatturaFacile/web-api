<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\WithholdingRecipients\Domain\Enums\ActivityType;
use Modules\WithholdingRecipients\Domain\Enums\EnasarcoMandate;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;

/**
 * A payee of commissions, royalties or special compensations and the facts the tax engines need about them.
 *
 * @property string|null $contact_id
 * @property string $display_name
 * @property string $regime
 * @property string $jurisdiction
 * @property string|null $tax_code
 * @property string|null $vat_number
 * @property string|null $avs_number
 * @property Carbon|null $birth_date
 * @property string|null $country_of_residence
 * @property string|null $canton
 * @property string|null $enasarco_mandate
 * @property bool $firr_enabled
 * @property bool $is_cultural_sector
 * @property string|null $activity_type
 * @property string|null $cu_causal
 * @property string $third_party_ytd_income
 * @property int|null $third_party_income_year
 * @property Carbon|null $third_party_declared_on
 * @property bool $is_active
 * @property string|null $notes
 * @property string|null $created_by
 * @property string|null $updated_by
 */
final class RecipientProfile extends BaseEnterpriseModel
{
    protected $table = 'fiscal_recipient_profiles';

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'third_party_declared_on' => 'date',
            'third_party_ytd_income' => 'decimal:2',
            'third_party_income_year' => 'integer',
            'firr_enabled' => 'boolean',
            'is_cultural_sector' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function regimeEnum(): RecipientRegime
    {
        return RecipientRegime::from($this->regime);
    }

    public function mandateEnum(): ?EnasarcoMandate
    {
        return $this->enasarco_mandate === null ? null : EnasarcoMandate::from($this->enasarco_mandate);
    }

    public function activityEnum(): ?ActivityType
    {
        return $this->activity_type === null ? null : ActivityType::from($this->activity_type);
    }

    /**
     * @return HasMany<AgentReducedWithholdingCert, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(AgentReducedWithholdingCert::class, 'recipient_profile_id');
    }

    /**
     * @return HasMany<OccasionalInlNotification, $this>
     */
    public function inlNotifications(): HasMany
    {
        return $this->hasMany(OccasionalInlNotification::class, 'recipient_profile_id');
    }

    /**
     * @return HasMany<RecipientSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(RecipientSettlement::class, 'recipient_profile_id');
    }
}
