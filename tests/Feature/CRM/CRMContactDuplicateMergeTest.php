<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Consent;
use Modules\CRM\Models\ContactMerge;
use Modules\CRM\Services\PartyMaster\ContactMergeService;

uses(RefreshDatabase::class);

/**
 * Duplicate detection with key normalisation and the audited, transactional merge of contacts.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Merge Tenant', 'legal_name' => 'Merge Tenant SpA']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create(['name' => 'Data Steward', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->actingAs($this->user);
    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);

    $this->insertInvoice = function (string $contactId, string $status): string {
        $id = (string) Str::uuid7();
        DB::table('invoices')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contactId,
            'invoice_number' => 'INV-'.Str::random(8),
            'date' => now()->toDateString(),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    };
});

test('duplicate groups are found through normalised VAT, email and phone, transitively', function (): void {
    $a = Contact::factory()->create(['company_name' => 'Alpha SpA', 'vat_number' => 'IT 01234567890', 'email' => 'a@alpha.it', 'phone' => '111']);
    $b = Contact::factory()->create(['company_name' => 'Alpha S.p.A.', 'vat_number' => '01234567890', 'email' => 'other@alpha.it', 'phone' => null]);
    $c = Contact::factory()->create(['company_name' => 'Alpha Group', 'vat_number' => null, 'email' => 'OTHER@alpha.it ', 'phone' => null]);
    Contact::factory()->create(['company_name' => 'Unrelated', 'vat_number' => '99999999999', 'email' => 'x@unrelated.it', 'phone' => '+39 333 1234567']);
    $d = Contact::factory()->create(['company_name' => 'Phone Twin', 'vat_number' => null, 'email' => 'p@twin.it', 'phone' => '0039 333 1234567']);

    $response = $this->getJson('/api/v1/crm/duplicates')->assertOk()->assertJsonPath('mode', 'groups');

    $groups = collect($response->json('data'));
    $alpha = $groups->first(fn (array $group): bool => collect($group['records'])->pluck('id')->contains($a->id));
    expect(collect($alpha['records'])->pluck('id')->sort()->values()->all())->toBe(collect([$a->id, $b->id, $c->id])->sort()->values()->all())
        ->and($alpha['matched_on'])->toContain('vat_number', 'email')
        ->and($groups->first(fn (array $group): bool => collect($group['records'])->pluck('id')->contains($d->id))['matched_on'])->toBe(['phone']);

    $this->getJson('/api/v1/crm/duplicates?vat_number=IT01234567890')->assertOk()
        ->assertJsonPath('mode', 'matches')
        ->assertJsonCount(2, 'data');
});

test('the deprecated lead duplicates endpoint still answers and advertises its successor', function (): void {
    $this->postJson('/api/v1/crm/leads', ['first_name' => 'Lia', 'email' => 'lia@x.it', 'phone' => '+39 02 555 1234'])->assertCreated();
    $this->postJson('/api/v1/crm/leads', ['first_name' => 'Lia bis', 'email' => 'other@x.it', 'phone' => '02 5551234'])->assertCreated();

    $this->getJson('/api/v1/crm/leads/duplicates?email=lia@x.it')
        ->assertOk()
        ->assertHeader('X-Deprecated', 'true')
        ->assertHeader('Link', '</api/v1/crm/duplicates?entity=leads>; rel="successor-version"');

    $this->getJson('/api/v1/crm/duplicates?entity=leads')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.matched_on', ['phone']);
});

test('a merge repoints every reference in one transaction, keeps issued invoices and is audited', function (): void {
    $survivor = Contact::factory()->create(['company_name' => 'Keep Srl', 'vat_number' => null, 'pec' => null]);
    $merged = Contact::factory()->create(['company_name' => 'Keep S.r.l.', 'vat_number' => 'IT55566677788', 'pec' => 'keep@pec.it']);

    $account = Account::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'account_number' => 'ACC-M', 'name' => 'Keep', 'contact_id' => $merged->id]);
    $activity = Activity::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'subject' => 'Call', 'type' => 'call', 'related_type' => 'contact', 'related_id' => $merged->id]);
    $consent = Consent::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'contact_id' => $merged->id, 'channel' => 'email', 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'web_form', 'captured_at' => now()]);
    $draft = ($this->insertInvoice)($merged->id, 'draft');
    $posted = ($this->insertInvoice)($merged->id, 'posted');

    $response = $this->postJson('/api/v1/crm/contact-merges', ['survivor_id' => $survivor->id, 'merged_id' => $merged->id])->assertCreated();

    $repointed = $response->json('repointed');
    expect($repointed['crm_accounts.contact_id'])->toBe(1)
        ->and($repointed['invoices.contact_id'])->toBe(1)
        ->and($repointed['crm_activities.related_id'])->toBe(1)
        ->and($repointed['crm_consents.contact_id'])->toBe(1)
        ->and($response->json('retained'))->toBe(['invoices.contact_id' => 1])
        ->and($response->json('filled_fields'))->toContain('vat_number', 'pec');

    app(TenantContext::class)->setTenant($this->tenant);
    expect(Account::find($account->id)->contact_id)->toBe($survivor->id)
        ->and(Activity::find($activity->id)->related_id)->toBe($survivor->id)
        ->and(Consent::find($consent->id)->contact_id)->toBe($survivor->id)
        ->and(DB::table('invoices')->where('id', $draft)->value('contact_id'))->toBe($survivor->id)
        ->and(DB::table('invoices')->where('id', $posted)->value('contact_id'))->toBe($merged->id)
        ->and(Contact::find($merged->id))->toBeNull()
        ->and(Contact::withTrashed()->find($merged->id))->not->toBeNull()
        ->and(Contact::find($survivor->id)->vat_number)->toBe('IT55566677788')
        ->and(AuditLedgerEntry::where('action', 'CONTACTS_MERGED')->count())->toBe(1)
        ->and(TransactionalOutbox::where('event_type', 'crm.contacts.merged')->count())->toBe(1);

    $this->getJson('/api/v1/crm/contact-merges')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.merged_snapshot.company_name', 'Keep S.r.l.');
});

test('a merge that cannot move a reference changes nothing', function (): void {
    $survivor = Contact::factory()->create();
    $merged = Contact::factory()->create();
    $account = Account::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'account_number' => 'ACC-R', 'name' => 'Rollback', 'contact_id' => $merged->id]);

    // A unique index that the repoint would violate stands for any constraint a module may add.
    DB::statement('CREATE UNIQUE INDEX test_one_account_per_contact ON crm_accounts (contact_id) WHERE deleted_at IS NULL');
    Account::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'account_number' => 'ACC-S', 'name' => 'Survivor account', 'contact_id' => $survivor->id]);

    expect(fn () => app(ContactMergeService::class)->merge((string) $this->tenant->id, $survivor->id, $merged->id, null))
        ->toThrow(\DomainException::class);

    expect(Contact::find($merged->id))->not->toBeNull()
        ->and(Account::find($account->id)->contact_id)->toBe($merged->id)
        ->and(ContactMerge::count())->toBe(0);
});

test('contacts of another tenant cannot be merged', function (): void {
    $survivor = Contact::factory()->create();
    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SpA']);
    $foreign = app(TenantContext::class)->runWithTenant($other, fn () => Contact::factory()->create());

    $this->postJson('/api/v1/crm/contact-merges', ['survivor_id' => $survivor->id, 'merged_id' => $foreign->id])
        ->assertUnprocessable()->assertJsonValidationErrors('merged_id');
    $this->postJson('/api/v1/crm/contact-merges', ['survivor_id' => $survivor->id, 'merged_id' => $survivor->id])
        ->assertUnprocessable();

    app(TenantContext::class)->setTenant($other);
    expect(Contact::find($foreign->id))->not->toBeNull();
});
