<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Models;

use App\Models\Contact;
use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\CorporateConventions\Domain\Enums\BeneficiaryType;
use Modules\CorporateConventions\Domain\Enums\EnrollmentStatus;
use Modules\CorporateConventions\Domain\Enums\Relationship;
use Modules\CorporateConventions\Domain\Enums\VerificationMethod;
use Modules\CorporateConventions\Domain\Support\Dec;
use Modules\CorporateConventions\Domain\ValueObjects\BeneficiaryBalance;
use Modules\CorporateConventions\Exceptions\ConventionException;

/**
 * Aggregate root of a beneficiary of a convention (employee or first-degree family member): the verification state, the digital
 * pass (wallet pass id and QR payload) and what the beneficiary has consumed of the annual caps. Family members hang from the
 * enrolment of the employee.
 *
 * @property string $convention_id
 * @property string $customer_id
 * @property BeneficiaryType $beneficiary_type
 * @property string|null $parent_enrollment_id
 * @property Relationship $relationship
 * @property string $full_name
 * @property string|null $verified_email
 * @property string|null $membership_card_number
 * @property string|null $identifier_hash
 * @property VerificationMethod $verification_method
 * @property Carbon|null $verified_at
 * @property Carbon|null $expires_at
 * @property EnrollmentStatus $status
 * @property string $wallet_pass_id
 * @property string $qr_payload
 * @property int|null $usage_year
 * @property string $annual_used_amount
 * @property string $annual_used_subsidy
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $deleted_by
 */
final class ConventionBeneficiaryEnrollment extends BaseEnterpriseModel
{
    protected $table = 'sales_convention_enrollments';

    protected function casts(): array
    {
        return [
            'beneficiary_type' => BeneficiaryType::class,
            'relationship' => Relationship::class,
            'verification_method' => VerificationMethod::class,
            'status' => EnrollmentStatus::class,
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'usage_year' => 'integer',
            'annual_used_amount' => 'decimal:2',
            'annual_used_subsidy' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CorporatePartnerConvention, $this>
     */
    public function convention(): BelongsTo
    {
        return $this->belongsTo(CorporatePartnerConvention::class, 'convention_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_enrollment_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function familyMembers(): HasMany
    {
        return $this->hasMany(self::class, 'parent_enrollment_id');
    }

    public function isFamilyMember(): bool
    {
        return $this->beneficiary_type === BeneficiaryType::FamilyMember;
    }

    public function isUsable(CarbonInterface $now): bool
    {
        return $this->status === EnrollmentStatus::Active && ($this->expires_at === null || $this->expires_at->gt($now));
    }

    /**
     * Consumption of the given year; a new year starts from zero. The employer subsidy reaches only the employee, never the family.
     */
    public function balanceFor(int $year): BeneficiaryBalance
    {
        $current = $this->usage_year === $year;

        return new BeneficiaryBalance(
            $current ? (string) $this->annual_used_amount : '0',
            $current ? (string) $this->annual_used_subsidy : '0',
            ! $this->isFamilyMember(),
        );
    }

    public function registerUsage(string $spend, string $subsidy, int $year): void
    {
        $base = $this->balanceFor($year);

        $this->usage_year = $year;
        $this->annual_used_amount = Dec::round(Dec::add($base->usedAmount, $spend));
        $this->annual_used_subsidy = Dec::round(Dec::add($base->usedSubsidy, $subsidy));
    }

    public function activate(CarbonInterface $now, ?CarbonInterface $expiresAt): void
    {
        $this->status = EnrollmentStatus::Active;
        $this->verified_at = Carbon::instance($now);
        $this->expires_at = $expiresAt === null ? null : Carbon::instance($expiresAt);
    }

    public function revoke(): void
    {
        if ($this->status === EnrollmentStatus::Revoked) {
            throw new ConventionException('enrollment_already_revoked', 409);
        }

        $this->status = EnrollmentStatus::Revoked;
    }

    public function expire(): void
    {
        if ($this->status === EnrollmentStatus::Active) {
            $this->status = EnrollmentStatus::Expired;
        }
    }
}
