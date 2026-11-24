<?php

declare(strict_types=1);

namespace Modules\CRM\Services\PartyMaster;

use App\Models\Contact;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\PartyLinkLog;

/**
 * Keeps the contact as the single owner of a CRM account's master data (legal name, VAT number, fiscal code, email,
 * phone, PEC, SDI code, country). The account keeps only CRM attributes (tier, owner, health, lifecycle); its `name`
 * column is a synchronised copy of the contact's company name kept for backward compatibility of list ordering and
 * existing clients.
 */
final readonly class AccountPartyService
{
    /**
     * Master data fields owned by the contact, as accepted by the account endpoints.
     */
    public const array MasterFields = ['vat_number', 'fiscal_code', 'email', 'phone', 'pec', 'sdi_code', 'country'];

    public function __construct(private ContactMatcher $matcher) {}

    /**
     * Creates an account and its link to the party master in one transaction, so that no account exists without a
     * contact.
     *
     * @param  array<string, mixed>  $data  validated StoreAccountRequest data
     */
    public function createAccount(string $tenantId, array $data, ?string $actorUserId): Account
    {
        return DB::transaction(function () use ($tenantId, $data, $actorUserId): Account {
            $contact = $this->resolveContact(
                $tenantId,
                (string) ($data['name'] ?? ''),
                Arr::only($data, self::MasterFields),
                $data['contact_id'] ?? null,
            );

            $name = filled($data['name'] ?? null)
                ? (string) $data['name']
                : ($contact->company_name ?: trim("{$contact->first_name} {$contact->last_name}"));

            return Account::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'contact_id' => $contact->id,
                'parent_account_id' => $data['parent_account_id'] ?? null,
                'name' => $name,
                'account_number' => 'ACC-'.strtoupper(Str::random(6)),
                'tier' => $data['tier'] ?? 'standard',
                'industry' => $data['industry'] ?? null,
                'website' => $data['website'] ?? null,
                'owner_user_id' => $data['owner_user_id'] ?? $actorUserId,
                'customer_health_index' => 100.00,
                'churn_risk' => 'low',
            ]);
        });
    }

    /**
     * Updates CRM attributes on the account and master data on its contact atomically. Relinking to another contact
     * marks a pending ambiguous consolidation decision as resolved.
     *
     * @param  array<string, mixed>  $data  validated UpdateAccountRequest data
     */
    public function updateAccount(Account $account, array $data): void
    {
        DB::transaction(function () use ($account, $data): void {
            $account->update(Arr::only($data, [
                'contact_id', 'tier', 'industry', 'website', 'status', 'annual_revenue', 'employee_count', 'tags', 'owner_user_id',
            ]));

            if (array_key_exists('contact_id', $data)) {
                PartyLinkLog::where('tenant_id', $account->tenant_id)
                    ->where('account_id', $account->id)
                    ->where('outcome', 'ambiguous')
                    ->update(['outcome' => 'resolved', 'contact_id' => $data['contact_id']]);
            }

            $account->load('primaryContact');
            $this->syncMasterData($account, $data);
        });
    }

    /**
     * The contact a new account must reference: the given one, else the single contact carrying the same VAT number,
     * fiscal code or email, else a new customer contact built from the request.
     *
     * @param  array<string, mixed>  $masterData
     */
    public function resolveContact(string $tenantId, string $name, array $masterData, ?string $contactId = null): Contact
    {
        if ($contactId !== null) {
            return Contact::query()->where('tenant_id', $tenantId)->findOrFail($contactId);
        }

        $existing = $this->matcher->findUnique(
            $tenantId,
            $masterData['vat_number'] ?? null,
            $masterData['fiscal_code'] ?? null,
            $masterData['email'] ?? null,
        );

        if ($existing !== null) {
            return $existing;
        }

        $contact = new Contact([
            ...Arr::only(array_filter($masterData, fn (mixed $value): bool => $value !== null), self::MasterFields),
            'type' => 'customer',
            'company_name' => $name,
        ]);
        $contact->tenant_id = $tenantId;
        $contact->save();

        return $contact;
    }

    /**
     * Writes the master data received on an account endpoint to the account's contact, and mirrors the company name
     * back on the account. Fields that are absent are left untouched.
     *
     * @param  array<string, mixed>  $input
     */
    public function syncMasterData(Account $account, array $input): void
    {
        $contact = $account->primaryContact;

        if (! $contact instanceof Contact) {
            return;
        }

        $changes = Arr::only($input, self::MasterFields);
        if (array_key_exists('name', $input) && filled($input['name'])) {
            $changes['company_name'] = $input['name'];
        }

        if ($changes !== []) {
            $contact->fill($changes)->save();
        }

        if ($contact->company_name !== null && $contact->company_name !== $account->name) {
            $account->update(['name' => $contact->company_name]);
        }
    }
}
