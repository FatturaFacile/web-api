<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Portal\PortalTokenService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierDocument;
use Modules\Procurement\Services\RfqService;
use Modules\SupplierPortal\Mail\SupplierPortalAccessMail;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;
use Modules\SupplierPortal\Models\SupplierOnboarding;
use Modules\SupplierPortal\Services\SupplierPortalAccessService;
use Modules\SupplierPortal\Services\TaxIdentifierValidator;

uses(RefreshDatabase::class);

const SP_CURRENT_IBAN = 'IT60X0542811101000000123456';
const SP_NEW_IBAN = 'CH9300762011623852957';

beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->travelTo('2027-06-10 08:00:00');
    $this->tenant = Tenant::create(['name' => 'Officine Rossi', 'legal_name' => 'Officine Rossi Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->admin = spMemberOf($this->tenant, 'admin');
    $this->buyer = spMemberOf($this->tenant, 'purchasing');
});

afterEach(fn () => app(TenantContext::class)->clear());

function spMemberOf(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.it', 'password' => Str::password(20), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => null]);

    return $user;
}

function supplierPortalActAs(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);
    test()->flushHeaders();

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

function spPortal(string $token): mixed
{
    test()->flushHeaders();

    return test()->withHeader('X-Supplier-Token', $token);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function spSupplier(Tenant $tenant, array $attributes = []): Supplier
{
    return app(TenantContext::class)->runWithTenant($tenant, static function () use ($tenant, $attributes): Supplier {
        $supplier = Supplier::factory()->approved()->create(['tenant_id' => $tenant->id, 'qualification_status' => 'prospect', 'is_approved_vendor' => false, ...$attributes]);
        Contact::query()->whereKey($supplier->contact_id)->update(['iban' => SP_CURRENT_IBAN, 'email' => 'fornitore'.Str::random(5).'@example.it']);

        return $supplier->refresh();
    });
}

/**
 * Invites the supplier as a purchaser and returns the onboarding and the portal token mailed to the supplier.
 *
 * @return array{0: array<string, mixed>, 1: string}
 */
function spInvite(Tenant $tenant, User $actor, Supplier $supplier, string $country = 'IT'): array
{
    supplierPortalActAs($actor, $tenant);
    $onboarding = test()->postJson('/api/v1/supplier-portal/onboardings', ['supplier_id' => $supplier->id, 'country' => $country, 'invitation_email' => 'forn@example.it'])->assertCreated()->json('data');
    $token = Mail::sent(SupplierPortalAccessMail::class)->last()->token;

    return [$onboarding, $token];
}

/**
 * @return array<string, string> requirement ids by code, as the supplier sees them
 */
function spRequirementIds(string $token): array
{
    $requirements = spPortal($token)->getJson('/api/v1/supplier-self-service/me')->assertOk()->json('data.requirements');

    return collect($requirements)->pluck('id', 'code')->all();
}

test('tax identifiers: Italian partita IVA and Swiss UID are checked for format and check digit', function (): void {
    $validator = new TaxIdentifierValidator;

    expect($validator->isValid('IT', '00743110157'))->toBeTrue()
        ->and($validator->isValid('IT', 'IT 00743110157'))->toBeTrue()
        ->and($validator->isValid('IT', '00743110158'))->toBeFalse()
        ->and($validator->isValid('IT', '0074311015'))->toBeFalse()
        ->and($validator->isValid('CH', 'CHE-116.281.710'))->toBeTrue()
        ->and($validator->isValid('CH', 'CHE-116.281.710 MWST'))->toBeTrue()
        ->and($validator->isValid('CH', 'CHE-116.281.711'))->toBeFalse()
        ->and($validator->isValid('CH', '00743110157'))->toBeFalse();
});

test('a supplier goes from invitation to approval, and the approval is gated on documents and qualification', function (): void {
    $supplier = spSupplier($this->tenant);

    supplierPortalActAs($this->buyer, $this->tenant)->postJson('/api/v1/supplier-portal/document-requirements/seed-defaults')->assertOk()->assertJsonPath('created', 10);
    $this->postJson('/api/v1/supplier-portal/document-requirements/seed-defaults')->assertOk()->assertJsonPath('created', 0);

    [$onboarding, $token] = spInvite($this->tenant, $this->buyer, $supplier);
    expect($onboarding['status'])->toBe('invited')->and($onboarding['allowed_transitions'])->toBe(['registration_submitted', 'rejected']);
    Mail::assertSent(SupplierPortalAccessMail::class, fn (SupplierPortalAccessMail $mail): bool => $mail->hasTo('forn@example.it'));
    expect(Supplier::query()->withoutGlobalScopes()->find($supplier->id)->qualification_status)->toBe('in_qualification');

    supplierPortalActAs($this->buyer, $this->tenant)->postJson('/api/v1/supplier-portal/onboardings', ['supplier_id' => $supplier->id, 'country' => 'IT'])->assertUnprocessable();

    $requirements = spRequirementIds($token);
    expect($requirements)->toHaveCount(7)->toHaveKeys(['DURC', 'VISURA_CAMERALE', 'ANTIMAFIA', 'INSURANCE_RC', 'ISO_9001'])->not->toHaveKey('UID_EXTRACT');

    $registration = ['legal_name' => 'Fornitore Spa', 'country' => 'IT', 'vat_number' => '00743110157', 'address_line' => 'Via Roma 1', 'postal_code' => '20100', 'city' => 'Milano', 'contact_name' => 'Giulia Bianchi', 'contact_email' => 'giulia@fornitore.it'];
    spPortal($token)->postJson('/api/v1/supplier-self-service/registration', [...$registration, 'vat_number' => '00743110158'])->assertUnprocessable()->assertJsonValidationErrors('vat_number');
    spPortal($token)->postJson('/api/v1/supplier-self-service/registration', $registration)->assertOk()->assertJsonPath('data.status', 'registration_submitted');

    spPortal($token)->postJson('/api/v1/supplier-self-service/submit-for-review')->assertUnprocessable();

    foreach (['DURC' => '2027-07-01', 'VISURA_CAMERALE' => '2027-12-01', 'INSURANCE_RC' => '2028-06-01'] as $code => $expiresAt) {
        spPortal($token)->post('/api/v1/supplier-self-service/documents', [
            'requirement_id' => $requirements[$code],
            'reference' => $code.'-1',
            'issued_at' => '2027-05-30',
            'expires_at' => $expiresAt,
            'file' => UploadedFile::fake()->create(strtolower($code).'.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.submitted_via', 'portal');
    }

    spPortal($token)->postJson('/api/v1/supplier-self-service/submit-for-review')->assertOk()->assertJsonPath('data.status', 'under_review');
    spPortal($token)->postJson('/api/v1/supplier-self-service/registration', $registration)->assertUnprocessable();

    $admin = supplierPortalActAs($this->admin, $this->tenant);
    $detail = $admin->getJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}")->assertOk()->json('data');
    expect(collect($detail['blockers'])->pluck('code')->unique()->values()->all())->toBe(['document_not_accepted', 'qualification_missing']);
    $admin->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/approve")->assertUnprocessable();

    $submissions = $admin->getJson('/api/v1/supplier-portal/documents?status=submitted')->assertOk()->json('data');
    expect($submissions)->toHaveCount(3);
    $durc = collect($submissions)->firstWhere('requirement_code', 'DURC');
    $admin->postJson("/api/v1/supplier-portal/documents/{$durc['id']}/accept", ['expires_at' => '2027-06-01'])->assertUnprocessable();
    foreach ($submissions as $submission) {
        $admin->postJson("/api/v1/supplier-portal/documents/{$submission['id']}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
    }
    expect(SupplierDocument::query()->withoutGlobalScopes()->where('supplier_id', $supplier->id)->where('is_mandatory', true)->count())->toBe(3);

    $admin->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/approve")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'qualification_missing'));

    $criteria = [];
    foreach ([['QUALITY', '50'], ['DELIVERY', '30'], ['FINANCIAL', '20']] as [$code, $weight]) {
        $criteria[$code] = $admin->postJson('/api/v1/supplier-portal/qualification-criteria', ['code' => $code, 'name' => ucfirst(strtolower($code)), 'weight' => $weight, 'max_score' => 5])->assertCreated()->json('data.id');
    }

    $assessment = $admin->postJson('/api/v1/supplier-portal/qualification-assessments', ['supplier_id' => $supplier->id, 'onboarding_id' => $onboarding['id'], 'scores' => [['criterion_id' => $criteria['QUALITY'], 'score' => 5]]])->assertCreated()->json('data');
    $admin->postJson("/api/v1/supplier-portal/qualification-assessments/{$assessment['id']}/complete")->assertUnprocessable();
    $admin->putJson("/api/v1/supplier-portal/qualification-assessments/{$assessment['id']}/scores", ['scores' => [['criterion_id' => $criteria['DELIVERY'], 'score' => 6]]])->assertUnprocessable();
    $admin->putJson("/api/v1/supplier-portal/qualification-assessments/{$assessment['id']}/scores", ['scores' => [['criterion_id' => $criteria['DELIVERY'], 'score' => 3], ['criterion_id' => $criteria['FINANCIAL'], 'score' => 4]]])->assertOk();
    $admin->postJson("/api/v1/supplier-portal/qualification-assessments/{$assessment['id']}/complete")->assertOk()
        ->assertJsonPath('data.total_score', '84.00')
        ->assertJsonPath('data.band', 'A')
        ->assertJsonPath('data.outcome', 'qualified');
    $admin->putJson("/api/v1/supplier-portal/qualification-assessments/{$assessment['id']}/scores", ['scores' => [['criterion_id' => $criteria['DELIVERY'], 'score' => 1]]])->assertUnprocessable();

    supplierPortalActAs($this->buyer, $this->tenant)->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/approve")->assertForbidden();

    supplierPortalActAs($this->admin, $this->tenant)->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/approve", ['notes' => 'Visited the plant'])->assertOk()->assertJsonPath('data.status', 'approved');
    $approved = Supplier::query()->withoutGlobalScopes()->find($supplier->id);
    expect($approved->qualification_status)->toBe('qualified')->and($approved->is_approved_vendor)->toBeTrue();

    $this->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/block")->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/block", ['reason' => 'Fraud suspicion'])->assertOk()->assertJsonPath('data.status', 'blocked');
    $blocked = Supplier::query()->withoutGlobalScopes()->find($supplier->id);
    expect($blocked->qualification_status)->toBe('disqualified')->and($blocked->is_approved_vendor)->toBeFalse();
    $this->getJson("/api/v1/supplier-portal/suppliers/{$supplier->id}/risk")->assertOk()->assertJsonPath('data.risk.level', 'critical');
    $this->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/approve")->assertUnprocessable();
    $this->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/start-review")->assertOk()->assertJsonPath('data.status', 'under_review');
});

test('a rejected supplier is disqualified and goes back to review, never straight to approval', function (): void {
    $supplier = spSupplier($this->tenant);
    [$onboarding] = spInvite($this->tenant, $this->buyer, $supplier);

    supplierPortalActAs($this->admin, $this->tenant)->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/approve")->assertUnprocessable();
    $this->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/reject", ['reason' => 'No answer'])->assertOk()->assertJsonPath('data.status', 'rejected');
    expect(Supplier::query()->withoutGlobalScopes()->find($supplier->id)->qualification_status)->toBe('disqualified');

    $this->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/approve")->assertUnprocessable();
    $this->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/start-review")->assertOk()->assertJsonPath('data.status', 'under_review');
    $this->getJson('/api/v1/supplier-portal/summary')->assertOk()->assertJsonPath('data.onboardings_by_status.under_review', 1);
});

test('failed checks stop the approval and raise the risk flag: a blocked VAT is high risk', function (): void {
    $supplier = spSupplier($this->tenant);
    supplierPortalActAs($this->buyer, $this->tenant);

    $this->postJson('/api/v1/supplier-portal/checks', ['supplier_id' => $supplier->id, 'check_type' => 'vies_vat', 'result' => 'passed'])->assertCreated();
    $this->getJson("/api/v1/supplier-portal/suppliers/{$supplier->id}/risk")->assertOk()->assertJsonPath('data.risk.level', 'none');

    $this->postJson('/api/v1/supplier-portal/checks', ['supplier_id' => $supplier->id, 'check_type' => 'vat_status', 'result' => 'failed', 'notes' => 'VAT position blocked'])->assertCreated();
    $risk = $this->getJson("/api/v1/supplier-portal/suppliers/{$supplier->id}/risk")->assertOk()->json('data');
    expect($risk['risk']['level'])->toBe('high')->and($risk['risk']['reasons'][0]['code'])->toBe('vat_blocked');

    $this->postJson('/api/v1/supplier-portal/checks', ['supplier_id' => $supplier->id, 'check_type' => 'sanctions', 'result' => 'failed'])->assertCreated();
    $this->getJson('/api/v1/supplier-portal/risk?min_level=high')->assertOk()->assertJsonPath('data.0.level', 'critical');

    [$onboarding] = spInvite($this->tenant, $this->buyer, $supplier);
    supplierPortalActAs($this->admin, $this->tenant)->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/reject", ['reason' => 'x'])->assertOk();
    $this->postJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}/start-review")->assertOk();
    $blockers = collect($this->getJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}")->json('data.blockers'))->pluck('code')->all();
    expect($blockers)->toContain('check_failed')->toContain('risk_critical');

    $this->postJson('/api/v1/supplier-portal/checks', ['supplier_id' => $supplier->id, 'check_type' => 'vat_status', 'result' => 'passed'])->assertCreated();
    $this->postJson('/api/v1/supplier-portal/checks', ['supplier_id' => $supplier->id, 'check_type' => 'sanctions', 'result' => 'passed'])->assertCreated();
    $this->getJson("/api/v1/supplier-portal/suppliers/{$supplier->id}/risk")->assertJsonPath('data.risk.level', 'none');
});

test('weighted qualification uses exact decimals and the configured bands', function (): void {
    $supplier = spSupplier($this->tenant);
    $admin = supplierPortalActAs($this->admin, $this->tenant);
    $one = $admin->postJson('/api/v1/supplier-portal/qualification-criteria', ['code' => 'ONE', 'name' => 'One', 'weight' => '33.33', 'max_score' => 3])->assertCreated()->json('data.id');
    $two = $admin->postJson('/api/v1/supplier-portal/qualification-criteria', ['code' => 'TWO', 'name' => 'Two', 'weight' => '66.67', 'max_score' => 10])->assertCreated()->json('data.id');
    $admin->postJson('/api/v1/supplier-portal/qualification-criteria', ['code' => 'ONE', 'name' => 'Dup', 'weight' => '1'])->assertUnprocessable()->assertJsonValidationErrors('code');

    $score = static fn (string $first, string $second): string => test()->postJson('/api/v1/supplier-portal/qualification-assessments', ['supplier_id' => $supplier->id, 'scores' => [['criterion_id' => $one, 'score' => $first], ['criterion_id' => $two, 'score' => $second]]])->assertCreated()->json('data.id');

    $cases = [['3', '10', '100.00', 'A', 'qualified'], ['2', '7', '68.89', 'B', 'conditional'], ['1', '5', '44.44', 'C', 'not_qualified'], ['3', '6', '73.33', 'B', 'conditional']];

    foreach ($cases as [$first, $second, $total, $band, $outcome]) {
        $id = $score($first, $second);
        $admin->postJson("/api/v1/supplier-portal/qualification-assessments/{$id}/complete")->assertOk()
            ->assertJsonPath('data.total_score', $total)->assertJsonPath('data.band', $band)->assertJsonPath('data.outcome', $outcome);
    }

    $admin->getJson("/api/v1/supplier-portal/qualification-assessments?supplier_id={$supplier->id}&status=completed")->assertOk()->assertJsonCount(4, 'data');
});

test('expiring documents alert the purchasing staff once per step, expire the qualification and raise the risk', function (): void {
    $supplier = spSupplier($this->tenant);
    supplierPortalActAs($this->buyer, $this->tenant)->postJson('/api/v1/supplier-portal/document-requirements/seed-defaults')->assertOk();
    [, $token] = spInvite($this->tenant, $this->buyer, $supplier);
    $requirements = spRequirementIds($token);

    $send = function (string $code, string $expiresAt) use ($token, $requirements): string {
        $id = spPortal($token)->post('/api/v1/supplier-self-service/documents', ['requirement_id' => $requirements[$code], 'issued_at' => '2027-05-30', 'expires_at' => $expiresAt, 'file' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        supplierPortalActAs($this->buyer, $this->tenant)->postJson("/api/v1/supplier-portal/documents/{$id}/accept")->assertOk();

        return $id;
    };

    $durc = $send('DURC', '2027-07-01');
    $send('VISURA_CAMERALE', '2027-06-14');
    $send('INSURANCE_RC', '2028-06-01');
    app(TenantContext::class)->runWithTenant($this->tenant, static fn () => Supplier::query()->whereKey($supplier->id)->update(['qualification_status' => 'qualified']));

    $this->travelTo('2027-06-12 05:50:00');
    $this->artisan('supplier-portal:alert-expiring-documents')->assertSuccessful();

    $notifications = $this->admin->notifications()->get();
    expect($notifications)->toHaveCount(2)
        ->and($notifications->pluck('data.type')->unique()->all())->toBe(['supplier_document_expiring'])
        ->and($notifications->pluck('data.days_left')->sort()->values()->all())->toBe([2, 19])
        ->and($this->buyer->notifications()->count())->toBe(2);

    $this->artisan('supplier-portal:alert-expiring-documents')->assertSuccessful();
    expect($this->admin->notifications()->count())->toBe(2);

    $this->travelTo('2027-07-05 05:50:00');
    $this->artisan('supplier-portal:alert-expiring-documents')->assertSuccessful();

    expect($this->admin->notifications()->get()->pluck('data.type')->all())->toContain('supplier_document_expired')
        ->and($this->admin->notifications()->count())->toBe(4);
    expect(Supplier::query()->withoutGlobalScopes()->find($supplier->id)->qualification_status)->toBe('expired');

    $this->artisan('supplier-portal:alert-expiring-documents')->assertSuccessful();
    expect($this->admin->notifications()->count())->toBe(4);

    $risk = supplierPortalActAs($this->buyer, $this->tenant)->getJson("/api/v1/supplier-portal/suppliers/{$supplier->id}/risk")->assertOk()->json('data.risk');
    expect(collect($risk['reasons'])->pluck('code')->all())->toContain('document_expired')->toContain('qualification_expired');
    $this->getJson('/api/v1/supplier-portal/documents?expiring_within_days=30')->assertOk()->assertJsonCount(2, 'data');

    $renewal = spPortal($token)->post('/api/v1/supplier-self-service/documents', ['requirement_id' => $requirements['DURC'], 'issued_at' => '2027-07-04', 'file' => UploadedFile::fake()->create('durc.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    supplierPortalActAs($this->buyer, $this->tenant)->postJson("/api/v1/supplier-portal/documents/{$renewal}/accept")->assertOk()->assertJsonPath('data.expires_at', '2027-11-01');
    expect(SupplierDocumentSubmission::query()->withoutGlobalScopes()->find($durc)->status)->toBe('superseded')
        ->and(SupplierDocument::query()->withoutGlobalScopes()->where('supplier_id', $supplier->id)->where('document_type', 'DURC')->where('is_mandatory', true)->count())->toBe(1);
});

test('a document is refused with a reason and replaced by a new one', function (): void {
    $supplier = spSupplier($this->tenant);
    supplierPortalActAs($this->buyer, $this->tenant)->postJson('/api/v1/supplier-portal/document-requirements/seed-defaults')->assertOk();
    $requirement = collect($this->getJson('/api/v1/supplier-portal/document-requirements?country=CH&active=1')->assertOk()->json('data'))->firstWhere('code', 'UID_EXTRACT');

    $first = $this->post('/api/v1/supplier-portal/documents', ['supplier_id' => $supplier->id, 'requirement_id' => $requirement['id'], 'file' => UploadedFile::fake()->create('uid.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/supplier-portal/documents/{$first}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/supplier-portal/documents/{$first}/reject", ['reason' => 'Illegible scan'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson("/api/v1/supplier-portal/documents/{$first}/accept")->assertUnprocessable();

    $second = $this->postJson('/api/v1/supplier-portal/documents', ['supplier_id' => $supplier->id, 'requirement_id' => $requirement['id'], 'issued_at' => '2027-06-01'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/supplier-portal/documents/{$second}/accept")->assertOk()->assertJsonPath('data.expires_at', '2027-11-28');
    $this->getJson("/api/v1/supplier-portal/documents/{$second}/download")->assertNotFound();

    $third = $this->post('/api/v1/supplier-portal/documents', ['supplier_id' => $supplier->id, 'requirement_id' => $requirement['id'], 'file' => UploadedFile::fake()->create('uid2.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->get("/api/v1/supplier-portal/documents/{$third}/download")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('a bank change is applied only after a callback and two approvals from people other than the requester', function (): void {
    $supplier = spSupplier($this->tenant);
    [, $token] = spInvite($this->tenant, $this->buyer, $supplier);
    $accountantOne = spMemberOf($this->tenant, 'accountant');
    $accountantTwo = spMemberOf($this->tenant, 'accountant');
    $payload = ['account_holder' => 'Fornitore Spa', 'iban' => SP_NEW_IBAN, 'bic' => 'UBSWCHZH80A', 'bank_name' => 'UBS', 'reason' => 'New bank'];

    spPortal($token)->postJson('/api/v1/supplier-self-service/bank-changes', [...$payload, 'iban' => 'IT60X0542811101000000123457'])->assertUnprocessable()->assertJsonValidationErrors('iban');
    spPortal($token)->postJson('/api/v1/supplier-self-service/bank-changes', [...$payload, 'iban' => SP_CURRENT_IBAN])->assertUnprocessable();
    $created = spPortal($token)->postJson('/api/v1/supplier-self-service/bank-changes', $payload)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
    spPortal($token)->postJson('/api/v1/supplier-self-service/bank-changes', $payload)->assertUnprocessable();
    spPortal($token)->getJson('/api/v1/supplier-self-service/bank-changes')->assertOk()->assertJsonPath('data.0.iban_masked', 'CH93*************2957');

    $request = supplierPortalActAs($accountantOne, $this->tenant)->getJson('/api/v1/supplier-portal/bank-changes?status=pending')->assertOk()->assertJsonPath('data.0.previous_iban', SP_CURRENT_IBAN)->json('data.0');
    expect($request['id'])->toBe($created['id'])->and($request['source'])->toBe('portal');

    $this->postJson("/api/v1/supplier-portal/bank-changes/{$request['id']}/approve")->assertUnprocessable();
    $this->postJson("/api/v1/supplier-portal/bank-changes/{$request['id']}/verify-callback")->assertUnprocessable()->assertJsonValidationErrors('note');
    supplierPortalActAs($this->buyer, $this->tenant)->postJson("/api/v1/supplier-portal/bank-changes/{$request['id']}/verify-callback", ['note' => 'called'])->assertForbidden();

    supplierPortalActAs($accountantOne, $this->tenant)->postJson("/api/v1/supplier-portal/bank-changes/{$request['id']}/verify-callback", ['note' => 'Called the number on the first contract'])->assertOk()->assertJsonPath('data.callback_verified_by', $accountantOne->id);
    $this->postJson("/api/v1/supplier-portal/bank-changes/{$request['id']}/approve", ['comment' => 'ok'])->assertOk()->assertJsonPath('data.status', 'pending');
    $this->postJson("/api/v1/supplier-portal/bank-changes/{$request['id']}/approve")->assertUnprocessable();
    expect(Contact::query()->withoutGlobalScopes()->find($supplier->contact_id)->iban)->toBe(SP_CURRENT_IBAN);

    supplierPortalActAs($accountantTwo, $this->tenant)->postJson("/api/v1/supplier-portal/bank-changes/{$request['id']}/approve")->assertOk()->assertJsonPath('data.status', 'applied')->assertJsonCount(2, 'data.approvals');
    expect(Contact::query()->withoutGlobalScopes()->find($supplier->contact_id)->iban)->toBe(SP_NEW_IBAN)
        ->and(Supplier::query()->withoutGlobalScopes()->find($supplier->id)->metadata['bank']['bank_name'])->toBe('UBS');
    $this->postJson("/api/v1/supplier-portal/bank-changes/{$request['id']}/reject", ['reason' => 'late'])->assertUnprocessable();
});

test('the requester never approves their own bank change and one rejection ends it', function (): void {
    $supplier = spSupplier($this->tenant);
    $payload = ['supplier_id' => $supplier->id, 'account_holder' => 'Fornitore Spa', 'iban' => SP_NEW_IBAN];

    $request = supplierPortalActAs($this->admin, $this->tenant)->postJson('/api/v1/supplier-portal/bank-changes', $payload)->assertCreated()->assertJsonPath('data.source', 'internal')->json('data');
    $this->postJson("/api/v1/supplier-portal/bank-changes/{$request['id']}/verify-callback", ['note' => 'self'])->assertForbidden();
    $this->postJson("/api/v1/supplier-portal/bank-changes/{$request['id']}/approve")->assertForbidden();
    $this->postJson('/api/v1/supplier-portal/bank-changes', $payload)->assertUnprocessable();

    $reviewer = spMemberOf($this->tenant, 'accountant');
    supplierPortalActAs($reviewer, $this->tenant)->postJson("/api/v1/supplier-portal/bank-changes/{$request['id']}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/supplier-portal/bank-changes/{$request['id']}/reject", ['reason' => 'Not confirmed by phone'])->assertOk()->assertJsonPath('data.status', 'rejected');
    expect(Contact::query()->withoutGlobalScopes()->find($supplier->contact_id)->iban)->toBe(SP_CURRENT_IBAN);

    $again = supplierPortalActAs($this->admin, $this->tenant)->postJson('/api/v1/supplier-portal/bank-changes', $payload)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/supplier-portal/bank-changes/{$again}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
});

test('invited suppliers quote before the deadline, the buyer compares the answers and the others stay hidden', function (): void {
    $suppliers = [spSupplier($this->tenant), spSupplier($this->tenant), spSupplier($this->tenant)];
    $tokens = [];

    foreach ($suppliers as $supplier) {
        [, $tokens[]] = spInvite($this->tenant, $this->buyer, $supplier);
        app(TenantContext::class)->runWithTenant($this->tenant, static fn () => Supplier::query()->whereKey($supplier->id)->update(['qualification_status' => 'qualified']));
    }

    $rfq = app(TenantContext::class)->runWithTenant($this->tenant, function () use ($suppliers) {
        $service = app(RfqService::class);
        $rfq = $service->create((string) $this->tenant->id, [
            'title' => 'Bearings',
            'response_deadline' => '2027-06-20',
            'supplier_ids' => collect($suppliers)->pluck('id')->all(),
            'lines' => [['description' => 'Bearing 6204', 'quantity' => '100'], ['description' => 'Bearing 6205', 'quantity' => '50']],
        ], null);

        return $service->issue($rfq);
    });
    $lines = $rfq->lines->pluck('id')->all();

    $listed = spPortal($tokens[0])->getJson('/api/v1/supplier-self-service/rfqs')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
    expect($listed['can_quote'])->toBeTrue()->and($listed['invitation_status'])->toBe('invited');

    $quote = ['quote_reference' => 'OFF-1', 'lead_time_days' => 10, 'lines' => [['rfq_line_id' => $lines[0], 'unit_price' => '1.255'], ['rfq_line_id' => $lines[1], 'unit_price' => '2.00', 'tax_rate' => '8.1']]];
    spPortal($tokens[0])->postJson("/api/v1/supplier-self-service/rfqs/{$rfq->id}/quote", ['lines' => []])->assertUnprocessable();
    spPortal($tokens[0])->postJson("/api/v1/supplier-self-service/rfqs/{$rfq->id}/quote", $quote)->assertCreated()->assertJsonPath('data.total_amount', '225.50')->assertJsonPath('data.lines.0.line_total', '125.50');
    spPortal($tokens[0])->postJson("/api/v1/supplier-self-service/rfqs/{$rfq->id}/quote", $quote)->assertUnprocessable();
    spPortal($tokens[0])->getJson("/api/v1/supplier-self-service/rfqs/{$rfq->id}")->assertOk()->assertJsonPath('data.quote.quote_reference', 'OFF-1')->assertJsonPath('data.can_quote', false);

    spPortal($tokens[1])->postJson("/api/v1/supplier-self-service/rfqs/{$rfq->id}/decline")->assertOk();
    spPortal($tokens[1])->postJson("/api/v1/supplier-self-service/rfqs/{$rfq->id}/decline")->assertUnprocessable();
    spPortal($tokens[1])->getJson("/api/v1/supplier-self-service/rfqs/{$rfq->id}")->assertOk()->assertJsonPath('data.quote', null)->assertJsonPath('data.invitation_status', 'declined');

    $this->travelTo('2027-06-21 09:00:00');
    spPortal($tokens[2])->postJson("/api/v1/supplier-self-service/rfqs/{$rfq->id}/quote", $quote)->assertUnprocessable();
    spPortal($tokens[2])->getJson('/api/v1/supplier-self-service/rfqs')->assertOk()->assertJsonPath('data.0.can_quote', false);

    $admin = supplierPortalActAs($this->buyer, $this->tenant);
    $admin->getJson('/api/v1/supplier-portal/rfq-responses')->assertOk()->assertJsonPath('data.0.invited_count', 3)->assertJsonPath('data.0.responded_count', 1)->assertJsonPath('data.0.declined_count', 1)->assertJsonPath('data.0.is_open', false);

    $comparison = $admin->getJson("/api/v1/supplier-portal/rfq-responses/{$rfq->id}/comparison")->assertOk()->json('data');
    expect($comparison['quotes'])->toHaveCount(1)
        ->and($comparison['recommended_quote_id'])->toBe($comparison['quotes'][0]['quote_id'])
        ->and(collect($comparison['invitations'])->pluck('status')->sort()->values()->all())->toBe(['declined', 'invited', 'responded']);

    $admin->postJson('/api/v1/supplier-portal/checks', ['supplier_id' => $suppliers[0]->id, 'check_type' => 'vat_status', 'result' => 'failed'])->assertCreated();
    $flagged = $admin->getJson("/api/v1/supplier-portal/rfq-responses/{$rfq->id}/comparison")->assertOk()->json('data');
    expect($flagged['recommended_quote_id'])->toBeNull()->and($flagged['quotes'][0]['risk_level'])->toBe('high');
});

test('a supplier sees its own purchase orders from the moment they are sent, and the status of its invoices', function (): void {
    $supplier = spSupplier($this->tenant);
    $other = spSupplier($this->tenant);
    [, $token] = spInvite($this->tenant, $this->buyer, $supplier);

    $orders = app(TenantContext::class)->runWithTenant($this->tenant, static fn (): array => [
        'sent' => PurchaseOrder::factory()->create(['tenant_id' => $supplier->tenant_id, 'supplier_id' => $supplier->id, 'status' => 'sent']),
        'draft' => PurchaseOrder::factory()->create(['tenant_id' => $supplier->tenant_id, 'supplier_id' => $supplier->id, 'status' => 'draft']),
        'foreign' => PurchaseOrder::factory()->create(['tenant_id' => $supplier->tenant_id, 'supplier_id' => $other->id, 'status' => 'sent']),
    ]);

    app(TenantContext::class)->runWithTenant($this->tenant, static function () use ($supplier, $other): void {
        PassiveInvoice::create(['tenant_id' => $supplier->tenant_id, 'supplier_id' => $supplier->id, 'invoice_number' => 'FP-1', 'date' => '2027-06-01', 'total_amount' => 610, 'currency' => 'EUR', 'payment_status' => 'unpaid'])->forceFill(['approval_status' => 'in_approval'])->save();
        PassiveInvoice::create(['tenant_id' => $supplier->tenant_id, 'supplier_id' => $supplier->id, 'invoice_number' => 'FP-2', 'date' => '2027-06-02', 'total_amount' => 122, 'currency' => 'EUR'])->forceFill(['approval_status' => PassiveInvoice::MatchBlockedStatus])->save();
        PassiveInvoice::create(['tenant_id' => $supplier->tenant_id, 'supplier_id' => $other->id, 'invoice_number' => 'XX-9', 'date' => '2027-06-02', 'total_amount' => 5, 'currency' => 'EUR']);
    });

    $list = spPortal($token)->getJson('/api/v1/supplier-self-service/purchase-orders')->assertOk()->assertJsonCount(1, 'data')->json('data');
    expect($list[0]['id'])->toBe($orders['sent']->id);
    spPortal($token)->getJson("/api/v1/supplier-self-service/purchase-orders/{$orders['sent']->id}")->assertOk()->assertJsonPath('data.order_number', $orders['sent']->order_number);
    spPortal($token)->getJson("/api/v1/supplier-self-service/purchase-orders/{$orders['draft']->id}")->assertNotFound();
    spPortal($token)->getJson("/api/v1/supplier-self-service/purchase-orders/{$orders['foreign']->id}")->assertNotFound();

    $invoices = spPortal($token)->getJson('/api/v1/supplier-self-service/invoices')->assertOk()->assertJsonCount(2, 'data')->json('data');
    expect(collect($invoices)->pluck('processing_status', 'invoice_number')->all())->toBe(['FP-2' => 'on_hold', 'FP-1' => 'in_approval']);
});

test('the supplier portal token is checked, bound to the supplier and kept out of the customer portal', function (): void {
    $supplier = spSupplier($this->tenant);
    [, $token] = spInvite($this->tenant, $this->buyer, $supplier);

    test()->flushHeaders();
    $this->getJson('/api/v1/supplier-self-service/me')->assertUnauthorized();
    spPortal(Str::random(64))->getJson('/api/v1/supplier-self-service/me')->assertUnauthorized();

    $customerToken = app(TenantContext::class)->runWithTenant($this->tenant, fn (): string => app(PortalTokenService::class)->createToken(Contact::query()->findOrFail($supplier->contact_id), 'dashboard')['token']);
    spPortal($customerToken)->getJson('/api/v1/supplier-self-service/me')->assertUnauthorized();
    test()->flushHeaders();
    $this->getJson("/api/v1/portal/invoices/{$token}")->assertNotFound();

    spPortal($token)->getJson('/api/v1/supplier-self-service/me')->assertOk()->assertJsonPath('data.supplier.id', $supplier->id);

    app(TenantContext::class)->runWithTenant($this->tenant, static fn () => app(SupplierPortalAccessService::class)->issueAccess(SupplierOnboarding::query()->firstOrFail()));
    spPortal($token)->getJson('/api/v1/supplier-self-service/me')->assertUnauthorized();
});

test('records stay inside the company and ordinary members do not reach the module', function (): void {
    $supplier = spSupplier($this->tenant);
    [$onboarding] = spInvite($this->tenant, $this->buyer, $supplier);

    $otherTenant = Tenant::create(['name' => 'Altra Srl', 'legal_name' => 'Altra Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $stranger = spMemberOf($otherTenant, 'admin');
    supplierPortalActAs($stranger, $otherTenant)->getJson("/api/v1/supplier-portal/onboardings/{$onboarding['id']}")->assertNotFound();
    $this->getJson('/api/v1/supplier-portal/onboardings')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/supplier-portal/onboardings', ['supplier_id' => $supplier->id, 'country' => 'IT'])->assertUnprocessable()->assertJsonValidationErrors('supplier_id');

    $member = spMemberOf($this->tenant, 'member');
    supplierPortalActAs($member, $this->tenant)->getJson('/api/v1/supplier-portal/onboardings')->assertForbidden();

    $readOnly = spMemberOf($this->tenant, 'accountant_readonly');
    supplierPortalActAs($readOnly, $this->tenant)->getJson('/api/v1/supplier-portal/onboardings')->assertOk();
    $this->postJson('/api/v1/supplier-portal/checks', ['supplier_id' => $supplier->id, 'check_type' => 'other', 'result' => 'passed'])->assertForbidden();
});
