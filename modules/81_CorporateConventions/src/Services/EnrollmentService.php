<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Services;

use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\CorporateConventions\Domain\Enums\BeneficiaryType;
use Modules\CorporateConventions\Domain\Enums\EnrollmentStatus;
use Modules\CorporateConventions\Domain\Enums\Relationship;
use Modules\CorporateConventions\Domain\Enums\VerificationMethod;
use Modules\CorporateConventions\Domain\Support\IdentifierHasher;
use Modules\CorporateConventions\Exceptions\ConventionException;
use Modules\CorporateConventions\Mail\ConventionOtpMail;
use Modules\CorporateConventions\Models\ConventionAllowedHash;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Models\ConventionOtpChallenge;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;

/**
 * Verification and enrolment of beneficiaries: work e-mail OTP, membership card, SHA-256 matricola, static promo code, first-degree
 * family members, and the instant lookup of a pass at the till (QR payload, card, hash or e-mail).
 */
final class EnrollmentService
{
    public function requestOtp(CorporatePartnerConvention $convention, string $email, string $locale): ConventionOtpChallenge
    {
        $this->assertOpen($convention);

        if (! $convention->supports(VerificationMethod::WorkEmailOtp)) {
            throw new ConventionException('verification_method_not_supported', 422, ['method' => VerificationMethod::WorkEmailOtp->value]);
        }

        $email = Str::lower(trim($email));

        if (! $convention->acceptsEmail($email)) {
            throw new ConventionException('email_domain_not_allowed', 422);
        }

        $recent = ConventionOtpChallenge::query()->where('convention_id', $convention->id)->where('email', $email)->where('created_at', '>=', now()->subHour())->count();

        if ($recent >= (int) config('corporate_conventions.otp.max_requests_per_hour')) {
            throw new ConventionException('otp_rate_limited', 429);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = (int) config('corporate_conventions.otp.ttl_minutes');
        $challenge = ConventionOtpChallenge::query()->create([
            'tenant_id' => $convention->tenant_id,
            'convention_id' => $convention->id,
            'email' => $email,
            'otp_hash' => ConventionOtpChallenge::hashCode($code),
            'expires_at' => now()->addMinutes($ttl),
        ]);

        Mail::to($email)->send(new ConventionOtpMail($code, $convention->name, $ttl, $locale));

        return $challenge;
    }

    public function verifyOtp(CorporatePartnerConvention $convention, string $email, string $code, string $fullName, ?string $userId): ConventionBeneficiaryEnrollment
    {
        $this->assertOpen($convention);
        $email = Str::lower(trim($email));

        $challenge = ConventionOtpChallenge::query()
            ->where('convention_id', $convention->id)->where('email', $email)->whereNull('verified_at')
            ->orderByDesc('created_at')->first();

        if ($challenge === null) {
            throw new ConventionException('otp_invalid', 422);
        }

        if (! $challenge->isOpen()) {
            throw new ConventionException('otp_expired', 422);
        }

        if ($challenge->attempts >= (int) config('corporate_conventions.otp.max_attempts')) {
            throw new ConventionException('otp_attempts_exceeded', 429);
        }

        if (! $challenge->matches(trim($code))) {
            $challenge->increment('attempts');

            throw new ConventionException('otp_invalid', 422);
        }

        return DB::transaction(function () use ($challenge, $convention, $email, $fullName, $userId): ConventionBeneficiaryEnrollment {
            $challenge->forceFill(['verified_at' => now()])->save();

            $existing = ConventionBeneficiaryEnrollment::query()
                ->where('convention_id', $convention->id)->where('beneficiary_type', BeneficiaryType::PrimaryEmployee->value)
                ->where('verified_email', $email)->first();

            if ($existing === null) {
                return $this->issue($convention, [
                    'beneficiary_type' => BeneficiaryType::PrimaryEmployee,
                    'relationship' => Relationship::Own,
                    'full_name' => $fullName,
                    'verified_email' => $email,
                    'verification_method' => VerificationMethod::WorkEmailOtp,
                ], null, $userId);
            }

            $this->assertNotRevoked($existing);
            $existing->activate(now(), $this->expiry($convention));
            $existing->save();

            return $existing;
        });
    }

    /**
     * Enrols the holder of a membership card, a matricola on the employer list (stored as SHA-256) or the static promo code.
     */
    public function enrollByIdentifier(CorporatePartnerConvention $convention, string $identifier, string $fullName, ?string $email, ?string $userId): ConventionBeneficiaryEnrollment
    {
        $this->assertOpen($convention);
        $identifier = trim($identifier);
        $hash = IdentifierHasher::hash($identifier);

        return DB::transaction(function () use ($convention, $identifier, $hash, $fullName, $email, $userId): ConventionBeneficiaryEnrollment {
            $listed = $convention->supports(VerificationMethod::HashedEmployeeList)
                ? ConventionAllowedHash::query()->where('convention_id', $convention->id)->where('identifier_sha256', $hash)->lockForUpdate()->first()
                : null;

            if ($listed !== null) {
                if ($listed->is_used) {
                    throw new ConventionException('identifier_already_used', 409);
                }

                $enrollment = $this->issue($convention, [
                    'beneficiary_type' => BeneficiaryType::PrimaryEmployee,
                    'relationship' => Relationship::Own,
                    'full_name' => $fullName,
                    'verified_email' => $email === null ? null : Str::lower($email),
                    'identifier_hash' => $hash,
                    'verification_method' => VerificationMethod::HashedEmployeeList,
                ], null, $userId);
                $listed->forceFill(['is_used' => true, 'used_by_enrollment_id' => $enrollment->id])->save();

                return $enrollment;
            }

            if ($convention->supports(VerificationMethod::MembershipCardPrefix) && $convention->matchesCardNumber($identifier)) {
                $taken = ConventionBeneficiaryEnrollment::query()->where('convention_id', $convention->id)->where('membership_card_number', strtoupper($identifier))->exists();

                if ($taken) {
                    throw new ConventionException('identifier_already_used', 409);
                }

                return $this->issue($convention, [
                    'beneficiary_type' => BeneficiaryType::PrimaryEmployee,
                    'relationship' => Relationship::Own,
                    'full_name' => $fullName,
                    'verified_email' => $email === null ? null : Str::lower($email),
                    'membership_card_number' => strtoupper($identifier),
                    'identifier_hash' => $hash,
                    'verification_method' => VerificationMethod::MembershipCardPrefix,
                ], null, $userId);
            }

            if ($convention->supports(VerificationMethod::PromoCode) && $convention->matchesPromoCode($identifier)) {
                return $this->issue($convention, [
                    'beneficiary_type' => BeneficiaryType::PrimaryEmployee,
                    'relationship' => Relationship::Own,
                    'full_name' => $fullName,
                    'verified_email' => $email === null ? null : Str::lower($email),
                    'verification_method' => VerificationMethod::PromoCode,
                ], null, $userId);
            }

            throw new ConventionException('identifier_not_eligible', 422);
        });
    }

    /**
     * The enrolment a QR payload (FFCONV:<wallet pass id>), membership card, matricola or e-mail points to, whatever its state.
     */
    public function lookup(string $identifier, ?string $conventionId = null): ?ConventionBeneficiaryEnrollment
    {
        $identifier = trim($identifier);
        $prefix = (string) config('corporate_conventions.qr_prefix');
        $query = ConventionBeneficiaryEnrollment::query()->with('convention')->when($conventionId, fn (Builder $builder, string $id) => $builder->where('convention_id', $id));

        if (str_starts_with($identifier, $prefix)) {
            $passId = substr($identifier, strlen($prefix));

            return Str::isUuid($passId) ? $query->where('wallet_pass_id', $passId)->first() : null;
        }

        if (str_contains($identifier, '@')) {
            return $query->where('verified_email', Str::lower($identifier))->orderByDesc('verified_at')->first();
        }

        return $query->where(fn (Builder $inner) => $inner
            ->where('membership_card_number', strtoupper($identifier))
            ->orWhere('identifier_hash', IdentifierHasher::hash($identifier)))
            ->orderByDesc('verified_at')->first();
    }

    /**
     * The enrolment of a pass that can be used right now: it exists, is active and unexpired, and its convention is in force.
     */
    public function resolveUsable(string $identifier, ?string $conventionId = null): ConventionBeneficiaryEnrollment
    {
        $enrollment = $this->lookup($identifier, $conventionId);

        if ($enrollment === null) {
            throw new ConventionException('enrollment_not_found', 404);
        }

        $this->assertUsable($enrollment);

        return $enrollment;
    }

    public function assertUsable(ConventionBeneficiaryEnrollment $enrollment): void
    {
        if (! $enrollment->isUsable(now())) {
            throw new ConventionException('enrollment_not_usable', 409, ['status' => $enrollment->status->value]);
        }

        $this->assertOpen($enrollment->convention ?? $enrollment->convention()->firstOrFail());
    }

    public function registerFamilyMember(ConventionBeneficiaryEnrollment $parent, string $fullName, Relationship $relationship, ?string $userId): ConventionBeneficiaryEnrollment
    {
        $convention = $parent->convention()->firstOrFail();
        $this->assertUsable($parent);

        if ($parent->isFamilyMember()) {
            throw new ConventionException('family_parent_must_be_employee', 422);
        }

        if ($relationship === Relationship::Own) {
            throw new ConventionException('family_relationship_invalid', 422);
        }

        if (! $convention->allow_family_members) {
            throw new ConventionException('family_not_allowed', 409);
        }

        return DB::transaction(function () use ($parent, $convention, $fullName, $relationship, $userId): ConventionBeneficiaryEnrollment {
            $members = ConventionBeneficiaryEnrollment::query()->where('parent_enrollment_id', $parent->id)
                ->whereNotIn('status', [EnrollmentStatus::Revoked->value])->lockForUpdate()->get();

            if (! $convention->canAddFamilyMember($members->count())) {
                throw new ConventionException('family_limit_reached', 409, ['max' => $convention->max_family_members]);
            }

            if ($relationship === Relationship::Spouse && $members->contains(fn (ConventionBeneficiaryEnrollment $member): bool => $member->relationship === Relationship::Spouse)) {
                throw new ConventionException('family_spouse_duplicate', 409);
            }

            return $this->issue($convention, [
                'beneficiary_type' => BeneficiaryType::FamilyMember,
                'relationship' => $relationship,
                'full_name' => $fullName,
                'verification_method' => $parent->verification_method,
            ], $parent, $userId);
        });
    }

    public function revoke(ConventionBeneficiaryEnrollment $enrollment, ?string $userId): ConventionBeneficiaryEnrollment
    {
        return DB::transaction(function () use ($enrollment, $userId): ConventionBeneficiaryEnrollment {
            $enrollment->revoke();
            $enrollment->updated_by = $userId;
            $enrollment->save();

            foreach (ConventionBeneficiaryEnrollment::query()->where('parent_enrollment_id', $enrollment->id)->where('status', '!=', EnrollmentStatus::Revoked->value)->get() as $member) {
                $member->revoke();
                $member->save();
            }

            return $enrollment;
        });
    }

    public function assertOpen(CorporatePartnerConvention $convention): void
    {
        if (! $convention->isActiveOn(now())) {
            throw new ConventionException('convention_not_active', 409);
        }
    }

    public function expiry(CorporatePartnerConvention $convention): CarbonImmutable
    {
        $expiry = CarbonImmutable::now()->addMonths((int) config('corporate_conventions.pass_validity_months'));
        $end = $convention->valid_to === null ? null : CarbonImmutable::instance($convention->valid_to)->endOfDay();

        return $end !== null && $end->lt($expiry) ? $end : $expiry;
    }

    private function assertNotRevoked(ConventionBeneficiaryEnrollment $enrollment): void
    {
        if ($enrollment->status === EnrollmentStatus::Revoked) {
            throw new ConventionException('enrollment_revoked', 403);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function issue(CorporatePartnerConvention $convention, array $attributes, ?ConventionBeneficiaryEnrollment $parent, ?string $userId): ConventionBeneficiaryEnrollment
    {
        $passId = (string) Str::uuid7();
        $name = trim((string) $attributes['full_name']);
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        $customer = Contact::query()->create([
            'type' => 'customer',
            'company_name' => $name,
            'first_name' => $first,
            'last_name' => $last === '' ? null : $last,
            'email' => $attributes['verified_email'] ?? $parent?->verified_email,
            'country' => $convention->country_code,
        ]);

        return ConventionBeneficiaryEnrollment::query()->create([
            ...$attributes,
            'tenant_id' => $convention->tenant_id,
            'convention_id' => $convention->id,
            'customer_id' => $customer->id,
            'parent_enrollment_id' => $parent?->id,
            'status' => EnrollmentStatus::Active,
            'verified_at' => now(),
            'expires_at' => $parent->expires_at ?? $this->expiry($convention),
            'wallet_pass_id' => $passId,
            'qr_payload' => config('corporate_conventions.qr_prefix').$passId,
            'created_by' => $userId,
        ]);
    }
}
