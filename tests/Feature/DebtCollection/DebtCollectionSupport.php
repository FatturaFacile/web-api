<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\Tenant;
use Database\Seeders\DebtCollectionReferenceSeeder;
use Illuminate\Support\Str;
use Modules\DebtCollection\Models\DunningCase;
use Modules\Invoicing\Models\ReceivableItem;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * Builders of the dunning tests (Mod 014): a Swiss company with the seeded statutory rates and texts, an accountant signed
 * in, customers in Switzerland and Italy and open items on the customer ledger.
 */
function dcSetUp(object $test, string $country = 'CH'): void
{
    $test->tenant = Tenant::create(['name' => 'Recupero SA', 'legal_name' => 'Recupero SA', 'country' => $country, 'default_currency' => $country === 'CH' ? 'CHF' : 'EUR']);
    (new DebtCollectionReferenceSeeder)->run();
    $test->accountant = treasuryActor($test, 'accountant');
}

function dcCustomer(object $test, string $name, string $country = 'CH', array $overrides = []): Contact
{
    return treasuryInTenant($test->tenant, function () use ($test, $name, $country, $overrides): Contact {
        $contact = Contact::create(array_merge([
            'tenant_id' => $test->tenant->id,
            'company_name' => $name,
            'email' => Str::slug($name).'@example.com',
            'pec' => $country === 'IT' ? Str::slug($name).'@pec.example.it' : null,
            'type' => 'customer',
            'country' => $country,
            'document_language' => $country === 'IT' ? 'it' : 'de',
        ], $overrides));

        ContactAddress::create(['contact_id' => $contact->id, 'type' => 'billing', 'street' => 'Bahnhofstrasse', 'house_number' => '1', 'postal_code' => $country === 'CH' ? '8001' : '20121', 'city' => $country === 'CH' ? 'Zurich' : 'Milano', 'country' => $country, 'is_default' => true]);

        return $contact;
    });
}

function dcItem(object $test, Contact $customer, string $number, string $amount, string $dueDate, string $currency = 'CHF', array $overrides = []): ReceivableItem
{
    return treasuryInTenant($test->tenant, fn (): ReceivableItem => ReceivableItem::create(array_merge([
        'tenant_id' => $test->tenant->id,
        'customer_id' => $customer->id,
        'invoice_id' => null,
        'sequence' => 1,
        'document_type' => 'invoice',
        'document_number' => $number,
        'currency' => $currency,
        'original_amount' => $amount,
        'open_amount' => $amount,
        'issue_date' => date('Y-m-d', (int) strtotime($dueDate.' -30 days')),
        'due_date' => $dueDate,
        'status' => ReceivableItem::StatusOpen,
    ], $overrides)));
}

/**
 * Puts the dunning case of an item at a level, as if a notice had been sent.
 */
function dcCase(object $test, ReceivableItem $item, int $level, string $lastNoticeOn, string $deadlineOn): DunningCase
{
    return treasuryInTenant($test->tenant, fn (): DunningCase => DunningCase::create([
        'tenant_id' => $test->tenant->id,
        'customer_id' => $item->customer_id,
        'receivable_item_id' => $item->id,
        'jurisdiction' => 'CH',
        'currency' => $item->currency,
        'current_level' => $level,
        'last_notice_on' => $lastNoticeOn,
        'deadline_on' => $deadlineOn,
        'status' => 'open',
    ]));
}

/**
 * The proposal items of the API, keyed by document number.
 *
 * @return array<string, array<string, mixed>>
 */
function dcProposal(object $test, string $asOf, array $query = []): array
{
    $response = $test->getJson('/api/v1/debt-collection/proposals?'.http_build_query(array_merge(['as_of' => $asOf, 'include_excluded' => 1], $query)))->assertOk();

    return collect($response->json('data.items'))->keyBy('document_number')->all();
}
