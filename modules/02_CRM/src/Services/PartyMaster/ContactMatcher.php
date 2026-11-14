<?php

declare(strict_types=1);

namespace Modules\CRM\Services\PartyMaster;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds the existing contacts of a tenant that carry the same identifying keys (VAT number, fiscal code, email) as a
 * party about to be created, so CRM flows link to the party master instead of creating a second record of the same
 * customer. The SQL expressions mirror PartyKeyNormalizer.
 */
final class ContactMatcher
{
    /**
     * @return Collection<int, Contact>
     */
    public function findByKeys(string $tenantId, ?string $vatNumber, ?string $fiscalCode, ?string $email): Collection
    {
        $vat = PartyKeyNormalizer::vat($vatNumber);
        $fiscal = PartyKeyNormalizer::fiscalCode($fiscalCode);
        $mail = PartyKeyNormalizer::email($email);

        if ($vat === null && $fiscal === null && $mail === null) {
            return collect();
        }

        // The database narrows the candidates (separators stripped, key contained); the exact normalization is the
        // one of PartyKeyNormalizer, applied here so that every database agrees.
        $withoutSeparators = static fn (string $column): string => "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE({$column}, '')), ' ', ''), '-', ''), '.', ''), '/', '')";

        return Contact::query()
            ->where('tenant_id', $tenantId)
            ->where(function (Builder $query) use ($vat, $fiscal, $mail, $withoutSeparators): void {
                if ($vat !== null) {
                    $query->orWhereRaw($withoutSeparators('vat_number').' LIKE ?', ['%'.$vat.'%']);
                }
                if ($fiscal !== null) {
                    $query->orWhereRaw($withoutSeparators('fiscal_code').' LIKE ?', ['%'.$fiscal.'%']);
                }
                if ($mail !== null) {
                    $query->orWhereRaw('lower(trim(email)) = ?', [$mail]);
                }
            })
            ->orderBy('created_at')
            ->get()
            ->filter(static fn (Contact $contact): bool => ($vat !== null && PartyKeyNormalizer::vat($contact->vat_number) === $vat)
                || ($fiscal !== null && PartyKeyNormalizer::fiscalCode($contact->fiscal_code) === $fiscal)
                || ($mail !== null && PartyKeyNormalizer::email($contact->email) === $mail))
            ->values();
    }

    /**
     * The single contact matching the keys, or null when there is none or more than one (an ambiguous match is never
     * guessed: the caller creates or asks).
     */
    public function findUnique(string $tenantId, ?string $vatNumber, ?string $fiscalCode, ?string $email): ?Contact
    {
        $matches = $this->findByKeys($tenantId, $vatNumber, $fiscalCode, $email);

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
