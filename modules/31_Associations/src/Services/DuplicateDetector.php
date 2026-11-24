<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Services\Tenancy\TenantContext;
use Modules\Associations\Models\Member;
use Modules\Associations\Support\BlindIndex;

/**
 * Finds the person a set of data may already belong to. The tax code is decisive; the email and the name with the date
 * of birth only point at a possible duplicate, for instance because a family shares an address.
 */
final class DuplicateDetector
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<string, ?string>  $person
     * @return ?array{member_id: string, reason: string}
     */
    public function find(array $person): ?array
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $taxCodeHash = BlindIndex::taxCode($person['tax_code'] ?? null, $tenantId);

        if ($taxCodeHash !== null) {
            $memberId = Member::query()->where('tax_code_hash', $taxCodeHash)->value('id');

            if ($memberId !== null) {
                return ['member_id' => (string) $memberId, 'reason' => 'tax_code'];
            }
        }

        $email = strtolower(trim((string) ($person['email'] ?? '')));

        if ($email !== '') {
            $memberId = Member::query()->whereHas('contact', static fn ($query) => $query->where('email', $email))->value('id');

            if ($memberId !== null) {
                return ['member_id' => (string) $memberId, 'reason' => 'email'];
            }
        }

        return $this->byNameAndBirthDate($person);
    }

    /**
     * @param  array<string, ?string>  $person
     * @return ?array{member_id: string, reason: string}
     */
    private function byNameAndBirthDate(array $person): ?array
    {
        $firstName = mb_strtolower(trim((string) ($person['first_name'] ?? '')));
        $lastName = mb_strtolower(trim((string) ($person['last_name'] ?? '')));
        $birthDate = trim((string) ($person['birth_date'] ?? ''));

        if ($firstName === '' || $lastName === '' || $birthDate === '') {
            return null;
        }

        $sameName = Member::query()
            ->whereHas('contact', static fn ($query) => $query->whereRaw('lower(first_name) = ?', [$firstName])->whereRaw('lower(last_name) = ?', [$lastName]))
            ->get();

        $match = $sameName->first(static fn (Member $member): bool => $member->birth_date === $birthDate);

        return $match === null ? null : ['member_id' => (string) $match->id, 'reason' => 'name_birth_date'];
    }
}
