<?php

declare(strict_types=1);

namespace Modules\CRM\Services\PartyMaster;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Links every CRM account without a contact to the party master (`contacts`), so that invoices, quotes, orders and
 * CRM read the same customer record.
 *
 * Matching, per tenant and never across tenants:
 *  1. strong keys: VAT number, fiscal code or email stored in the account metadata, and the primary contact role;
 *     exactly one distinct contact links, several distinct contacts are ambiguous;
 *  2. otherwise the normalised company name: one contact links, several are ambiguous;
 *  3. no candidate at all: a contact is created from the account data.
 *
 * Ambiguous accounts stay unlinked and are written to `crm_party_link_logs` for a person to decide. Every decision is
 * logged once per account (upserted), and accounts that already have a contact are never touched, so running it again
 * changes nothing. It uses the query builder only, because it runs inside a migration where models may be newer than
 * the schema.
 */
final class AccountContactBackfill
{
    /**
     * @return array{linked: int, created: int, ambiguous: int}
     */
    public function run(?string $tenantId = null): array
    {
        $stats = ['linked' => 0, 'created' => 0, 'ambiguous' => 0];

        if (! Schema::hasTable('crm_accounts') || ! Schema::hasTable('crm_party_link_logs')) {
            return $stats;
        }

        $tenantIds = DB::table('crm_accounts')
            ->whereNull('contact_id')
            ->whereNull('deleted_at')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $currentTenantId) {
            $this->runForTenant((string) $currentTenantId, $stats);
        }

        return $stats;
    }

    /**
     * @param  array{linked: int, created: int, ambiguous: int}  $stats
     */
    private function runForTenant(string $tenantId, array &$stats): void
    {
        $index = $this->buildContactIndex($tenantId);

        DB::table('crm_accounts')
            ->where('tenant_id', $tenantId)
            ->whereNull('contact_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use ($tenantId, &$index, &$stats): void {
                foreach ($accounts as $account) {
                    DB::transaction(function () use ($tenantId, $account, &$index, &$stats): void {
                        $this->linkAccount($tenantId, $account, $index, $stats);
                    });
                }
            });
    }

    /**
     * @param  array<string, array<string, list<string>>>  $index
     * @param  array{linked: int, created: int, ambiguous: int}  $stats
     */
    private function linkAccount(string $tenantId, object $account, array &$index, array &$stats): void
    {
        $metadata = is_string($account->metadata ?? null) ? (array) json_decode($account->metadata, true) : [];
        $keys = [
            'vat_number' => PartyKeyNormalizer::vat($metadata['vat_number'] ?? null),
            'fiscal_code' => PartyKeyNormalizer::fiscalCode($metadata['fiscal_code'] ?? null),
            'email' => PartyKeyNormalizer::email($metadata['email'] ?? null),
        ];

        $strong = [];
        $matchedOn = [];
        foreach ($keys as $key => $value) {
            if ($value !== null && isset($index[$key][$value])) {
                $strong = [...$strong, ...$index[$key][$value]];
                $matchedOn[] = $key;
            }
        }

        $primaryRoleContact = DB::table('crm_contact_roles')
            ->where('tenant_id', $tenantId)
            ->where('account_id', $account->id)
            ->where('is_primary', true)
            ->whereNull('deleted_at')
            ->pluck('contact_id')
            ->all();
        if ($primaryRoleContact !== []) {
            $strong = [...$strong, ...array_map('strval', $primaryRoleContact)];
            $matchedOn[] = 'primary_contact_role';
        }

        $strong = array_values(array_unique($strong));

        if (count($strong) === 1) {
            $this->link($tenantId, $account->id, $strong[0], 'linked', implode(',', $matchedOn), $strong);
            $stats['linked']++;

            return;
        }

        if (count($strong) > 1) {
            $this->log($tenantId, $account->id, 'ambiguous', implode(',', $matchedOn), null, $strong, 'Several contacts share the identifying keys of this account.');
            $stats['ambiguous']++;

            return;
        }

        $name = PartyKeyNormalizer::name($account->name);
        $byName = $name !== null ? ($index['name'][$name] ?? []) : [];

        if (count($byName) === 1) {
            $this->link($tenantId, $account->id, $byName[0], 'linked', 'company_name', $byName);
            $stats['linked']++;

            return;
        }

        if (count($byName) > 1) {
            $this->log($tenantId, $account->id, 'ambiguous', 'company_name', null, $byName, 'Several contacts have the company name of this account.');
            $stats['ambiguous']++;

            return;
        }

        $contactId = $this->createContact($tenantId, $account, $metadata, $keys);
        foreach (['vat_number', 'fiscal_code', 'email'] as $key) {
            if ($keys[$key] !== null) {
                $index[$key][$keys[$key]][] = $contactId;
            }
        }

        $this->link($tenantId, $account->id, $contactId, 'created', null, []);
        $stats['created']++;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function link(string $tenantId, string $accountId, string $contactId, string $outcome, ?string $matchedOn, array $candidates): void
    {
        DB::table('crm_accounts')->where('id', $accountId)->whereNull('contact_id')->update([
            'contact_id' => $contactId,
            'updated_at' => now(),
        ]);

        $this->log($tenantId, $accountId, $outcome, $matchedOn, $contactId, $candidates, null);
    }

    /**
     * @param  list<string>  $candidates
     */
    private function log(string $tenantId, string $accountId, string $outcome, ?string $matchedOn, ?string $contactId, array $candidates, ?string $note): void
    {
        $values = [
            'tenant_id' => $tenantId,
            'outcome' => $outcome,
            'matched_on' => $matchedOn !== '' ? $matchedOn : null,
            'contact_id' => $contactId,
            'candidate_contact_ids' => json_encode($candidates),
            'note' => $note,
            'updated_at' => now(),
        ];

        $updated = DB::table('crm_party_link_logs')->where('account_id', $accountId)->update($values);

        if ($updated === 0) {
            DB::table('crm_party_link_logs')->insert([
                ...$values,
                'id' => (string) Str::uuid7(),
                'account_id' => $accountId,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array{vat_number: ?string, fiscal_code: ?string, email: ?string}  $keys
     */
    private function createContact(string $tenantId, object $account, array $metadata, array $keys): string
    {
        $contactId = (string) Str::uuid7();
        $country = strtoupper((string) ($metadata['country'] ?? 'IT'));

        DB::table('contacts')->insert([
            'id' => $contactId,
            'tenant_id' => $tenantId,
            'type' => 'customer',
            'country' => strlen($country) === 2 ? $country : 'IT',
            'company_name' => $account->name,
            'email' => $keys['email'],
            'phone' => isset($metadata['phone']) ? (string) $metadata['phone'] : null,
            'vat_number' => isset($metadata['vat_number']) ? substr((string) $metadata['vat_number'], 0, 30) : null,
            'fiscal_code' => isset($metadata['fiscal_code']) ? substr((string) $metadata['fiscal_code'], 0, 16) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $contactId;
    }

    /**
     * Maps each normalised key of the tenant's live contacts to the contact ids that carry it.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function buildContactIndex(string $tenantId): array
    {
        $index = ['vat_number' => [], 'fiscal_code' => [], 'email' => [], 'name' => []];

        DB::table('contacts')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->select(['id', 'company_name', 'vat_number', 'fiscal_code', 'email'])
            ->orderBy('id')
            ->chunk(500, function ($contacts) use (&$index): void {
                foreach ($contacts as $contact) {
                    $keys = [
                        'vat_number' => PartyKeyNormalizer::vat($contact->vat_number),
                        'fiscal_code' => PartyKeyNormalizer::fiscalCode($contact->fiscal_code),
                        'email' => PartyKeyNormalizer::email($contact->email),
                        'name' => PartyKeyNormalizer::name($contact->company_name),
                    ];

                    foreach ($keys as $key => $value) {
                        if ($value !== null) {
                            $index[$key][$value][] = (string) $contact->id;
                        }
                    }
                }
            });

        return $index;
    }
}
