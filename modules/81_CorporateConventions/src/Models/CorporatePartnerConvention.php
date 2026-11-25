<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Models;

use App\Models\Contact;
use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\CorporateConventions\Domain\Enums\BillingCycle;
use Modules\CorporateConventions\Domain\Enums\ConventionStatus;
use Modules\CorporateConventions\Domain\Enums\PartnerType;
use Modules\CorporateConventions\Domain\Enums\SubsidyPurpose;
use Modules\CorporateConventions\Domain\Enums\SubsidyType;
use Modules\CorporateConventions\Domain\Enums\VerificationMethod;
use Modules\CorporateConventions\Domain\Support\Dec;
use Modules\CorporateConventions\Domain\Support\DiscountMatrixValidator;
use Modules\CorporateConventions\Domain\ValueObjects\ConventionTerms;
use Modules\CorporateConventions\Domain\ValueObjects\DiscountRuleSpec;
use Modules\CorporateConventions\Exceptions\ConventionException;

/**
 * Aggregate root of a corporate convention: a partner (company, CRAL, order, union, university, public body, insurer, association)
 * whose members buy at a discount. It owns the lifecycle, the discount matrix, the eligibility rules (work e-mail domains, card
 * prefix, SHA-256 employee list, promo code) and the family and annual cap rules.
 *
 * @property string $code
 * @property string $slug
 * @property string $name
 * @property string $partner_company_name
 * @property string|null $partner_vat_number
 * @property string|null $partner_uid_number
 * @property string|null $partner_contact_id
 * @property PartnerType $partner_type
 * @property string $country_code
 * @property string $currency
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property ConventionStatus $status
 * @property int|null $eligible_population
 * @property string|null $hr_contact_name
 * @property string|null $hr_contact_email
 * @property string|null $hr_contact_phone
 * @property string|null $contract_document_path
 * @property list<string>|null $verification_methods
 * @property list<string>|null $allowed_email_domains
 * @property string|null $card_number_prefix
 * @property string|null $card_number_regex
 * @property string|null $static_convention_code
 * @property bool $allow_family_members
 * @property int $max_family_members
 * @property string|null $annual_spending_cap_per_beneficiary
 * @property bool $employer_subsidy_enabled
 * @property SubsidyType|null $employer_subsidy_type
 * @property string|null $employer_subsidy_value
 * @property string|null $employer_subsidy_annual_cap
 * @property SubsidyPurpose $subsidy_purpose
 * @property bool $split_billing_anonymized
 * @property BillingCycle $billing_cycle
 * @property string|null $logo_path
 * @property string|null $primary_color
 * @property string|null $welcome_message_key
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $deleted_by
 */
final class CorporatePartnerConvention extends BaseEnterpriseModel
{
    protected $table = 'sales_partner_conventions';

    /** @var array<string, list<ConventionStatus>> */
    private const array Transitions = [
        'DRAFT' => [ConventionStatus::Active],
        'ACTIVE' => [ConventionStatus::Suspended, ConventionStatus::Expired],
        'SUSPENDED' => [ConventionStatus::Active, ConventionStatus::Expired],
        'EXPIRED' => [],
    ];

    protected function casts(): array
    {
        return [
            'partner_type' => PartnerType::class,
            'status' => ConventionStatus::class,
            'subsidy_purpose' => SubsidyPurpose::class,
            'billing_cycle' => BillingCycle::class,
            'employer_subsidy_type' => SubsidyType::class,
            'valid_from' => 'date',
            'valid_to' => 'date',
            'verification_methods' => 'array',
            'allowed_email_domains' => 'array',
            'allow_family_members' => 'boolean',
            'employer_subsidy_enabled' => 'boolean',
            'split_billing_anonymized' => 'boolean',
            'annual_spending_cap_per_beneficiary' => 'decimal:2',
            'employer_subsidy_value' => 'decimal:4',
            'employer_subsidy_annual_cap' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<ConventionDiscountRule, $this>
     */
    public function discountRules(): HasMany
    {
        return $this->hasMany(ConventionDiscountRule::class, 'convention_id')->orderBy('priority');
    }

    /**
     * @return HasMany<ConventionAllowedHash, $this>
     */
    public function allowedHashes(): HasMany
    {
        return $this->hasMany(ConventionAllowedHash::class, 'convention_id');
    }

    /**
     * @return HasMany<ConventionBeneficiaryEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(ConventionBeneficiaryEnrollment::class, 'convention_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function partnerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'partner_contact_id');
    }

    public function isActiveOn(CarbonInterface $date): bool
    {
        return $this->status === ConventionStatus::Active
            && $this->valid_from->startOfDay()->lte($date)
            && ($this->valid_to === null || $this->valid_to->endOfDay()->gte($date));
    }

    public function supports(VerificationMethod $method): bool
    {
        return in_array($method->value, $this->verification_methods ?? [], true);
    }

    /**
     * Whether the address belongs to one of the partner e-mail domains ("@partner.it": exact domain, never a look-alike suffix).
     */
    public function acceptsEmail(string $email): bool
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return false;
        }

        $domain = strtolower(substr($email, $at + 1));

        foreach ($this->allowed_email_domains ?? [] as $allowed) {
            if (strtolower(ltrim((string) $allowed, '@')) === $domain) {
                return true;
            }
        }

        return false;
    }

    public function matchesCardNumber(string $card): bool
    {
        $card = trim($card);

        if ($card === '' || ($this->card_number_prefix === null && $this->card_number_regex === null)) {
            return false;
        }

        if ($this->card_number_prefix !== null && ! str_starts_with(strtoupper($card), strtoupper($this->card_number_prefix))) {
            return false;
        }

        return $this->card_number_regex === null || @preg_match('/'.str_replace('/', '\/', $this->card_number_regex).'/', $card) === 1;
    }

    public function matchesPromoCode(string $code): bool
    {
        return $this->static_convention_code !== null && $this->static_convention_code !== '' && hash_equals(strtoupper($this->static_convention_code), strtoupper(trim($code)));
    }

    public function canAddFamilyMember(int $current): bool
    {
        return $this->allow_family_members && $current < $this->max_family_members;
    }

    public function terms(): ConventionTerms
    {
        return new ConventionTerms(
            $this->annual_spending_cap_per_beneficiary,
            $this->employer_subsidy_enabled,
            $this->employer_subsidy_type,
            $this->employer_subsidy_value,
            $this->employer_subsidy_annual_cap,
        );
    }

    /**
     * @return list<DiscountRuleSpec>
     */
    public function ruleSpecs(): array
    {
        return $this->discountRules->map(fn (ConventionDiscountRule $rule): DiscountRuleSpec => $rule->toSpec())->values()->all();
    }

    /**
     * Lifecycle: draft → active ⇄ suspended → expired. Activating needs at least one verification method with its data and a valid matrix.
     */
    public function transitionTo(ConventionStatus $target): void
    {
        if (! in_array($target, self::Transitions[$this->status->value], true)) {
            throw new ConventionException('convention_transition_refused', 409, ['from' => $this->status->value, 'to' => $target->value]);
        }

        if ($target === ConventionStatus::Active) {
            $this->assertCanActivate();
        }

        $this->status = $target;
    }

    public function assertCanActivate(): void
    {
        if (($this->verification_methods ?? []) === []) {
            throw new ConventionException('convention_no_verification_method', 422);
        }

        foreach ($this->verification_methods ?? [] as $method) {
            $missing = match ($method) {
                VerificationMethod::WorkEmailOtp->value => ($this->allowed_email_domains ?? []) === [],
                VerificationMethod::MembershipCardPrefix->value => $this->card_number_prefix === null && $this->card_number_regex === null,
                VerificationMethod::PromoCode->value => $this->static_convention_code === null || $this->static_convention_code === '',
                VerificationMethod::HashedEmployeeList->value => ! $this->allowedHashes()->exists(),
                default => false,
            };

            if ($missing) {
                throw new ConventionException('convention_verification_data_missing', 422, ['method' => $method]);
            }
        }

        if ($this->discountRules()->doesntExist()) {
            throw new ConventionException('convention_discount_matrix_empty', 422);
        }

        DiscountMatrixValidator::assertValid($this->discountRules->map(fn (ConventionDiscountRule $rule): array => ['rule_type' => $rule->rule_type->value, 'discount_percentage' => $rule->discount_percentage, 'priority' => $rule->priority, 'target_price_list_id' => $rule->target_price_list_id, 'target_category_id' => $rule->target_category_id])->values()->all());
    }

    /**
     * Rules that hold whatever the lifecycle state: subsidy configuration and the period.
     */
    public function assertConsistent(): void
    {
        if ($this->valid_to !== null && $this->valid_to->lt($this->valid_from)) {
            throw new ConventionException('convention_period_invalid', 422);
        }

        if ($this->employer_subsidy_enabled && ($this->employer_subsidy_type === null || $this->employer_subsidy_value === null || Dec::cmp($this->employer_subsidy_value, '0') <= 0)) {
            throw new ConventionException('convention_subsidy_incomplete', 422);
        }

        if ($this->employer_subsidy_type === SubsidyType::Percentage && $this->employer_subsidy_value !== null && Dec::cmp($this->employer_subsidy_value, '100') > 0) {
            throw new ConventionException('convention_subsidy_percentage_invalid', 422);
        }

        if ($this->allow_family_members && $this->max_family_members < 1) {
            throw new ConventionException('convention_family_limit_missing', 422);
        }

        if ($this->card_number_regex !== null && @preg_match('/'.str_replace('/', '\/', $this->card_number_regex).'/', '') === false) {
            throw new ConventionException('convention_card_regex_invalid', 422);
        }

        if ($this->currency !== ($this->country_code === 'CH' ? 'CHF' : 'EUR')) {
            throw new ConventionException('convention_currency_country_mismatch', 422, ['country' => $this->country_code]);
        }
    }
}
