<?php

declare(strict_types=1);

namespace Modules\CRM\Services\PartyMaster;

use App\Models\Contact;
use Modules\CRM\Models\Lead;

/**
 * Detects duplicate parties within a tenant by normalised VAT number, fiscal code, email and phone. Records that share
 * any key are grouped transitively (A=B by VAT, B=C by email gives one group), and each group says which keys matched
 * so a person can decide the merge.
 */
final class ContactDuplicateFinder
{
    /**
     * Groups of duplicate contacts of the tenant.
     *
     * @return list<array{matched_on: list<string>, records: list<Contact>}>
     */
    public function contactGroups(string $tenantId, int $limit = 100): array
    {
        $contacts = Contact::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at')
            ->get(['id', 'tenant_id', 'type', 'company_name', 'first_name', 'last_name', 'vat_number', 'fiscal_code', 'email', 'phone', 'pec', 'sdi_code', 'country', 'created_at']);

        $keysOf = fn (Contact $contact): array => [
            'vat_number' => PartyKeyNormalizer::vat($contact->vat_number),
            'fiscal_code' => PartyKeyNormalizer::fiscalCode($contact->fiscal_code),
            'email' => PartyKeyNormalizer::email($contact->email),
            'phone' => PartyKeyNormalizer::phone($contact->phone),
        ];

        /** @var list<array{matched_on: list<string>, records: list<Contact>}> $groups */
        $groups = $this->group($contacts->all(), $keysOf, $limit);

        return $groups;
    }

    /**
     * Groups of duplicate leads of the tenant (email and phone, the keys a lead has).
     *
     * @return list<array{matched_on: list<string>, records: list<Lead>}>
     */
    public function leadGroups(string $tenantId, int $limit = 100): array
    {
        $leads = Lead::where('tenant_id', $tenantId)
            ->whereNotIn('status', ['converted'])
            ->orderBy('created_at')
            ->get();

        $keysOf = fn (Lead $lead): array => [
            'email' => PartyKeyNormalizer::email($lead->email),
            'phone' => PartyKeyNormalizer::phone($lead->phone),
        ];

        /** @var list<array{matched_on: list<string>, records: list<Lead>}> $groups */
        $groups = $this->group($leads->all(), $keysOf, $limit);

        return $groups;
    }

    /**
     * Contacts of the tenant matching a candidate's keys, used before creating a party.
     *
     * @param  array{vat_number?: ?string, fiscal_code?: ?string, email?: ?string, phone?: ?string}  $candidate
     * @return list<array{record: Contact, matched_on: list<string>}>
     */
    public function contactMatches(string $tenantId, array $candidate): array
    {
        $wanted = array_filter([
            'vat_number' => PartyKeyNormalizer::vat($candidate['vat_number'] ?? null),
            'fiscal_code' => PartyKeyNormalizer::fiscalCode($candidate['fiscal_code'] ?? null),
            'email' => PartyKeyNormalizer::email($candidate['email'] ?? null),
            'phone' => PartyKeyNormalizer::phone($candidate['phone'] ?? null),
        ]);

        if ($wanted === []) {
            return [];
        }

        $matches = [];
        foreach (Contact::query()->where('tenant_id', $tenantId)->cursor() as $contact) {
            $keys = [
                'vat_number' => PartyKeyNormalizer::vat($contact->vat_number),
                'fiscal_code' => PartyKeyNormalizer::fiscalCode($contact->fiscal_code),
                'email' => PartyKeyNormalizer::email($contact->email),
                'phone' => PartyKeyNormalizer::phone($contact->phone),
            ];
            $matchedOn = array_keys(array_filter($wanted, fn (string $value, string $key): bool => $keys[$key] === $value, ARRAY_FILTER_USE_BOTH));

            if ($matchedOn !== []) {
                $matches[] = ['record' => $contact, 'matched_on' => $matchedOn];
            }
        }

        return $matches;
    }

    /**
     * Leads of the tenant matching a candidate's email or phone.
     *
     * @param  array{email?: ?string, phone?: ?string}  $candidate
     * @return list<array{record: Lead, matched_on: list<string>}>
     */
    public function leadMatches(string $tenantId, array $candidate): array
    {
        $wanted = array_filter([
            'email' => PartyKeyNormalizer::email($candidate['email'] ?? null),
            'phone' => PartyKeyNormalizer::phone($candidate['phone'] ?? null),
        ]);

        if ($wanted === []) {
            return [];
        }

        $matches = [];
        foreach (Lead::where('tenant_id', $tenantId)->cursor() as $lead) {
            $keys = ['email' => PartyKeyNormalizer::email($lead->email), 'phone' => PartyKeyNormalizer::phone($lead->phone)];
            $matchedOn = array_keys(array_filter($wanted, fn (string $value, string $key): bool => $keys[$key] === $value, ARRAY_FILTER_USE_BOTH));

            if ($matchedOn !== []) {
                $matches[] = ['record' => $lead, 'matched_on' => $matchedOn];
            }
        }

        return $matches;
    }

    /**
     * Union-find over shared keys.
     *
     * @template TRecord of \Illuminate\Database\Eloquent\Model
     *
     * @param  list<TRecord>  $records
     * @param  callable(TRecord): array<string, ?string>  $keysOf
     * @return list<array{matched_on: list<string>, records: list<TRecord>}>
     */
    private function group(array $records, callable $keysOf, int $limit): array
    {
        $parent = array_keys($records);
        $find = function (int $index) use (&$parent, &$find): int {
            if ($parent[$index] !== $index) {
                $parent[$index] = $find($parent[$index]);
            }

            return $parent[$index];
        };

        $owners = [];
        $matchedOn = [];
        foreach ($records as $index => $record) {
            foreach ($keysOf($record) as $key => $value) {
                if ($value === null) {
                    continue;
                }

                $slot = $key.'|'.$value;
                if (! isset($owners[$slot])) {
                    $owners[$slot] = $index;

                    continue;
                }

                $rootA = $find($owners[$slot]);
                $rootB = $find($index);
                if ($rootA !== $rootB) {
                    $parent[$rootB] = $rootA;
                }
                $matchedOn[$slot] = $key;
            }
        }

        $grouped = [];
        foreach ($records as $index => $record) {
            $grouped[$find($index)][] = $index;
        }

        $result = [];
        foreach ($grouped as $members) {
            if (count($members) < 2) {
                continue;
            }

            $keys = [];
            foreach ($members as $index) {
                foreach ($keysOf($records[$index]) as $key => $value) {
                    if ($value !== null && isset($matchedOn[$key.'|'.$value])) {
                        $keys[$key] = true;
                    }
                }
            }

            $result[] = [
                'matched_on' => array_keys($keys),
                'records' => array_map(fn (int $index) => $records[$index], $members),
            ];

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }
}
