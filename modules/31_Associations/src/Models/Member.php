<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Associations\Enums\MembershipStatus;
use Modules\Associations\Enums\MemberStatus;
use Modules\Associations\Support\BlindIndex;

/**
 * A person of the registry of an association, whatever the type (member, volunteer, instructor...). The name, email and
 * phone are on the linked contact. Everything else personal is encrypted; the tax code can be compared through its
 * keyed hash.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $contact_id
 * @property ?string $family_id
 * @property MemberStatus $status
 * @property ?string $birth_date
 * @property ?string $tax_code
 * @property-read Contact $contact
 */
final class Member extends AssociationRecord
{
    public const int AgeOfMajority = 18;

    protected $table = 'assoc_members';

    protected $hidden = ['tax_code_hash'];

    protected static function booted(): void
    {
        self::saving(static function (self $member): void {
            if ($member->isDirty('tax_code')) {
                $member->tax_code_hash = BlindIndex::taxCode($member->tax_code, (string) ($member->tenant_id ?? app(TenantContext::class)->getTenantId()));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => MemberStatus::class,
            'joined_on' => 'date',
            'left_on' => 'date',
            'birth_date' => 'encrypted',
            'birth_place' => 'encrypted',
            'gender' => 'encrypted',
            'tax_code' => 'encrypted',
            'identity_document' => 'encrypted',
            'health_notes' => 'encrypted',
            'allergies' => 'encrypted',
            'emergency_contact' => 'encrypted',
            'custom_fields' => 'encrypted:array',
            'internal_notes' => 'encrypted',
        ];
    }

    /**
     * Calculated columns for the server-side grids: the display name lives on the linked contact.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'full_name' => self::nameSql('assoc_members.id'),
        ];
    }

    /**
     * Portable SQL (SQLite and PostgreSQL) of the display name of a person, for a column holding a member id.
     */
    public static function nameSql(string $memberIdColumn): string
    {
        return "SELECT COALESCE(NULLIF(TRIM(COALESCE(c.first_name, '') || ' ' || COALESCE(c.last_name, '')), ''), c.company_name) FROM assoc_members m INNER JOIN contacts c ON c.id = m.contact_id WHERE m.id = {$memberIdColumn}";
    }

    public function isMinor(?CarbonImmutable $on = null): bool
    {
        if ($this->birth_date === null) {
            return false;
        }

        return CarbonImmutable::parse($this->birth_date)->addYears(self::AgeOfMajority)->isAfter($on ?? CarbonImmutable::today());
    }

    public function displayName(): string
    {
        $contact = $this->contact;

        return trim("{$contact->first_name} {$contact->last_name}") ?: (string) $contact->company_name;
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'family_id');
    }

    public function registryAssignments(): HasMany
    {
        return $this->hasMany(RegistryAssignment::class, 'member_id');
    }

    public function socialRoles(): HasMany
    {
        return $this->hasMany(MemberSocialRole::class, 'member_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'member_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MemberDocument::class, 'member_id');
    }

    public function guardianships(): HasMany
    {
        return $this->hasMany(Guardianship::class, 'minor_member_id');
    }

    public function authorizations(): HasMany
    {
        return $this->hasMany(Authorization::class, 'minor_member_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(MedicalCertificate::class, 'member_id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class, 'member_id');
    }

    public function feeAssignments(): HasMany
    {
        return $this->hasMany(FeeAssignment::class, 'member_id');
    }

    public function courseEnrolments(): HasMany
    {
        return $this->hasMany(CourseEnrolment::class, 'member_id');
    }

    /**
     * The membership that counts on a day, if any.
     */
    public function currentMembership(?CarbonImmutable $on = null): ?Membership
    {
        $day = ($on ?? CarbonImmutable::today())->toDateString();

        /** @var ?Membership */
        return $this->memberships()
            ->where('status', MembershipStatus::Active)
            ->whereDate('valid_from', '<=', $day)
            ->whereDate('valid_to', '>=', $day)
            ->orderByDesc('valid_to')
            ->first();
    }
}
