<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Models\Contact;
use App\Models\ContactAddress;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\MemberStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MemberDocument;
use Modules\Associations\Models\MemberSocialRole;
use Modules\Associations\Models\RegistryAssignment;
use Modules\Associations\Models\RegistryType;
use Modules\Associations\Models\SocialRole;
use Modules\Associations\Support\BlindIndex;

/**
 * The people of the registry. The contact stays the single source of name, email and phone; nothing of it is copied.
 */
final class RegistryService
{
    public const array ContactFields = ['first_name', 'last_name', 'company_name', 'email', 'phone'];

    public const array MemberFields = [
        'birth_date', 'birth_place', 'gender', 'tax_code', 'identity_document', 'health_notes', 'allergies',
        'emergency_contact', 'internal_notes', 'family_id', 'joined_on', 'left_on', 'status',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CustomFieldValidator $customFields,
        private readonly PrivateFiles $files,
    ) {}

    /**
     * Registers a person, on an existing contact when contact_id is given, on a new one otherwise.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Member
    {
        return DB::transaction(function () use ($data): Member {
            $contactData = array_intersect_key($data, array_flip(self::ContactFields));
            $contact = isset($data['contact_id'])
                ? Contact::query()->findOrFail($data['contact_id'])
                : Contact::create($contactData + ['country' => $this->tenantContext->getTenant()?->country ?: 'IT']);

            if (Member::query()->where('contact_id', $contact->id)->exists()) {
                throw AssociationException::conflict(__('associations.contact_already_registered'));
            }

            $this->assertTaxCodeFree($data['tax_code'] ?? null);

            return Member::create([
                ...array_intersect_key($data, array_flip(self::MemberFields)),
                'contact_id' => $contact->id,
                'status' => $data['status'] ?? MemberStatus::Active,
                'joined_on' => $data['joined_on'] ?? CarbonImmutable::today()->toDateString(),
                'custom_fields' => $this->customFields->validate($data['custom_fields'] ?? []),
            ])->load('contact');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Member $member, array $data): Member
    {
        return DB::transaction(function () use ($member, $data): Member {
            if (array_key_exists('tax_code', $data)) {
                $this->assertTaxCodeFree($data['tax_code'], $member->id);
            }

            $contactData = array_intersect_key($data, array_flip(self::ContactFields));

            if ($contactData !== []) {
                $member->contact->update($contactData);
            }

            $memberData = array_intersect_key($data, array_flip(self::MemberFields));

            if (array_key_exists('custom_fields', $data)) {
                $memberData['custom_fields'] = $this->customFields->validate($data['custom_fields'] ?? []);
            }

            $member->update($memberData);

            return $member->refresh()->load('contact');
        });
    }

    /**
     * Contact data a person changes through the member area.
     *
     * @param  array{email?: ?string, phone?: ?string, address?: array<string, ?string>}  $data
     */
    public function updateContactData(Contact $contact, array $data): Contact
    {
        return DB::transaction(function () use ($contact, $data): Contact {
            $contact->update(array_intersect_key($data, array_flip(['email', 'phone'])));

            if (isset($data['address'])) {
                ContactAddress::query()->updateOrCreate(
                    ['contact_id' => $contact->id, 'type' => 'billing'],
                    $data['address'] + ['country' => $contact->country],
                );
            }

            return $contact->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assignType(Member $member, RegistryType $type, array $data = []): RegistryAssignment
    {
        return RegistryAssignment::create([
            'member_id' => $member->id,
            'registry_type_id' => $type->id,
            'year_id' => $data['year_id'] ?? null,
            'valid_from' => $data['valid_from'] ?? CarbonImmutable::today()->toDateString(),
            'valid_to' => $data['valid_to'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * An office with a single holder cannot be given while somebody else holds it in the same period.
     *
     * @param  array<string, mixed>  $data
     */
    public function assignRole(Member $member, SocialRole $role, array $data): MemberSocialRole
    {
        return DB::transaction(function () use ($member, $role, $data): MemberSocialRole {
            $from = (string) $data['valid_from'];
            $to = $data['valid_to'] ?? null;

            if ($role->is_single_holder) {
                SocialRole::query()->whereKey($role->id)->lockForUpdate()->first();

                $taken = MemberSocialRole::query()
                    ->where('social_role_id', $role->id)
                    ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from))
                    ->when($to !== null, static fn ($query) => $query->whereDate('valid_from', '<=', $to))
                    ->exists();

                if ($taken) {
                    throw AssociationException::conflict(__('associations.role_already_held'));
                }
            }

            return MemberSocialRole::create([
                'member_id' => $member->id,
                'social_role_id' => $role->id,
                'valid_from' => $from,
                'valid_to' => $to,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addDocument(Member $member, UploadedFile $file, array $data, ?string $uploadedBy): MemberDocument
    {
        return MemberDocument::create([
            ...$this->files->store($file, (string) $member->tenant_id, "members/{$member->id}"),
            'member_id' => $member->id,
            'title' => $data['title'],
            'category' => $data['category'] ?? 'other',
            'expires_on' => $data['expires_on'] ?? null,
            'visible_to_member' => (bool) ($data['visible_to_member'] ?? false),
            'uploaded_by' => $uploadedBy,
        ]);
    }

    private function assertTaxCodeFree(?string $taxCode, ?string $exceptMemberId = null): void
    {
        $hash = BlindIndex::taxCode($taxCode, (string) $this->tenantContext->getTenantId());

        if ($hash === null) {
            return;
        }

        $taken = Member::query()
            ->where('tax_code_hash', $hash)
            ->when($exceptMemberId !== null, static fn ($query) => $query->whereKeyNot($exceptMemberId))
            ->exists();

        if ($taken) {
            throw AssociationException::conflict(__('associations.tax_code_already_registered'));
        }
    }
}
