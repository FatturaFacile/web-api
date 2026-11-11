<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\EmployeeProfile;
use App\Models\Invoice;
use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\Translation;
use App\Models\User;
use App\Services\Portal\PortalTokenService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Integrations\Exceptions\PaymentGatewayException;
use Modules\Integrations\Services\PaymentGatewayResolver;
use Modules\Projects\Models\Project;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Models\ApprovalMatrixRule;
use Modules\Workflow\Models\ApprovalMatrixTier;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\ApprovalMatrixEvaluatorService;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA', 'vat_number' => 'IT11111111111', 'country' => 'IT']);
    $this->otherTenant = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA', 'vat_number' => 'IT22222222222', 'country' => 'IT']);
});

/**
 * A member of the tenant with the given role, signed in for the next requests.
 */
function hardeningActor(string $role, ?Tenant $tenant = null, array $attributes = []): User
{
    $tenant ??= test()->tenant;
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123', ...$attributes]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);

    return $user;
}

function hardeningInvoice(Tenant $tenant, string $number = 'INV-1'): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'company_name' => 'Cliente '.$number, 'type' => 'customer', 'country' => 'IT']);

    return Invoice::create([
        'tenant_id' => $tenant->id,
        'contact_id' => $contact->id,
        'invoice_number' => $number,
        'date' => now()->toDateString(),
        'total_net' => 100,
        'total_gross' => 122,
        'payment_status' => 'unpaid',
    ]);
}

// B1 ------------------------------------------------------------------ translations and lookups

test('a member cannot write translations, an admin writes only into the own tenant', function (): void {
    hardeningActor('member');
    $this->postJson('/api/v1/translations', ['locale' => 'en', 'key' => 'X', 'value' => 'Y'])->assertForbidden();

    hardeningActor('admin');
    $this->postJson('/api/v1/translations', ['locale' => 'en', 'key' => 'APP.TITLE', 'value' => 'Hijacked', 'tenant_id' => $this->otherTenant->id])->assertOk();

    expect(Translation::where('key', 'APP.TITLE')->pluck('tenant_id')->all())->toBe([$this->tenant->id]);
});

test('only a platform administrator writes the global dictionary', function (): void {
    hardeningActor('admin');
    $this->postJson('/api/v1/translations', ['locale' => 'en', 'key' => 'APP.TITLE', 'value' => 'Global', 'tenant_id' => null])->assertForbidden();

    $admin = hardeningActor('admin');
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->postJson('/api/v1/translations', ['locale' => 'en', 'key' => 'APP.TITLE', 'value' => 'Global', 'tenant_id' => null])->assertOk();

    expect(Translation::whereNull('tenant_id')->where('key', 'APP.TITLE')->value('value'))->toBe('Global');
});

test('a guest naming a tenant gets only the global translations and lookups', function (): void {
    Translation::createGlobal(['locale' => 'en', 'key' => 'APP.TITLE', 'value' => 'Public']);
    Translation::create(['tenant_id' => $this->tenant->id, 'locale' => 'en', 'key' => 'APP.SECRET', 'value' => 'Private']);

    $response = $this->getJson("/api/v1/translations/en?tenant_id={$this->tenant->id}")->assertOk();

    expect($response->json('tenant_id'))->toBeNull()
        ->and($response->json('translations'))->not->toHaveKey('APP.SECRET');

    $member = User::create(['name' => 'm', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $member->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    Cache::flush();

    $signedIn = $this->actingAs($member, 'sanctum')->getJson("/api/v1/translations/en?tenant_id={$this->tenant->id}")->assertOk();
    expect($signedIn->json('translations')['APP.SECRET'] ?? null)->toBe('Private');
});

test('lookup labels go to the own tenant and only for lookup items the tenant can see', function (): void {
    $category = LookupCategory::createGlobal(['code' => 'payment_type', 'name' => 'Payment type']);
    $foreignItem = LookupItem::create(['category_id' => $category->id, 'tenant_id' => $this->otherTenant->id, 'code' => 'SECRET', 'is_active' => true]);
    $globalItem = LookupItem::createGlobal(['category_id' => $category->id, 'code' => 'CASH', 'is_active' => true]);

    hardeningActor('admin');
    $this->postJson('/api/v1/lookups/translations', ['lookup_item_id' => $foreignItem->id, 'locale' => 'it', 'label' => 'X'])
        ->assertUnprocessable()->assertJsonValidationErrors('lookup_item_id');

    $this->postJson('/api/v1/lookups/translations', ['lookup_item_id' => $globalItem->id, 'locale' => 'it', 'label' => 'Contanti', 'tenant_id' => $this->otherTenant->id])->assertOk();
    $this->assertDatabaseHas('lookup_item_translations', ['lookup_item_id' => $globalItem->id, 'tenant_id' => $this->tenant->id, 'label' => 'Contanti']);
    $this->assertDatabaseMissing('lookup_item_translations', ['tenant_id' => $this->otherTenant->id]);

    $this->postJson('/api/v1/lookups/translations', ['lookup_item_id' => $globalItem->id, 'locale' => 'it', 'label' => 'Globale', 'tenant_id' => 'global'])->assertForbidden();
});

// B2 ------------------------------------------------------------------ tenant settings and VAT numbers

test('updating the company keeps preferences and the two-factor policy', function (): void {
    $this->tenant->update(['settings' => ['preferences' => ['company' => ['city' => 'Lugano']], 'security' => ['require_two_factor' => false]]]);

    hardeningActor('member');
    $this->putJson('/api/v1/settings/tenant', ['name' => 'Taken'])->assertForbidden();

    hardeningActor('admin');
    $this->putJson('/api/v1/settings/tenant', [
        'name' => 'Alfa Renamed',
        'settings' => ['regime_fiscale' => 'RF01', 'security' => ['require_two_factor' => true], 'preferences' => []],
    ])->assertOk();

    $settings = $this->tenant->fresh()->settings;

    expect($settings['preferences']['company']['city'])->toBe('Lugano')
        ->and($settings['security']['require_two_factor'])->toBeFalse()
        ->and($settings['regime_fiscale'])->toBe('RF01');
});

test('a VAT number already held by another tenant is refused, however it is written', function (string $vat): void {
    hardeningActor('owner');

    $this->putJson('/api/v1/settings/tenant', ['vat_number' => $vat])->assertUnprocessable()->assertJsonValidationErrors('vat_number');
})->with(['IT22222222222', '22222222222', 'it 222 222 222 22']);

test('the own VAT number can be saved again', function (): void {
    hardeningActor('owner');

    $this->putJson('/api/v1/settings/tenant', ['vat_number' => '11111111111'])->assertOk();
});

// B3 ------------------------------------------------------------------ cross-border commuters

test('cross-border commuters are for HR only and never expose pay or social security data', function (): void {
    $employee = User::create(['name' => 'Frontaliere', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    app(TenantContext::class)->setTenant($this->tenant);
    EmployeeProfile::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $employee->id,
        'is_cross_border_commuter' => true,
        'social_security_number' => '756.1234.5678.97',
        'base_salary' => 9000,
        'job_title' => 'Welder',
    ]);
    app(TenantContext::class)->clear();

    hardeningActor('member');
    $this->getJson('/api/v1/hr/frontalieri')->assertForbidden();

    hardeningActor('hr');
    $row = $this->getJson('/api/v1/hr/frontalieri')->assertOk()->json('data.0');

    expect($row['job_title'])->toBe('Welder')
        ->and($row)->not->toHaveKeys(['social_security_number', 'base_salary', 'swiss_cantonal_tax_rate', 'inps_code']);
});

// B4 ------------------------------------------------------------------ workflows

test('a workflow can start only on an allowed record of the own tenant', function (): void {
    $actor = hardeningActor('admin');

    $this->postJson('/api/v1/workflows/instances', ['workflow_code' => 'X', 'approvable_type' => Tenant::class, 'approvable_id' => $this->otherTenant->id])
        ->assertUnprocessable()->assertJsonValidationErrors('approvable_type');

    $foreignContact = Contact::create(['tenant_id' => $this->otherTenant->id, 'company_name' => 'Altro', 'type' => 'customer', 'country' => 'IT']);
    $foreignProject = Project::factory()->create(['tenant_id' => $this->otherTenant->id, 'contact_id' => $foreignContact->id, 'project_manager_user_id' => $actor->id]);

    $this->postJson('/api/v1/workflows/instances', ['workflow_code' => 'X', 'approvable_type' => 'project', 'approvable_id' => $foreignProject->id])
        ->assertNotFound();
});

test('a workflow on a record needs the write role of its module', function (): void {
    $actor = hardeningActor('warehouse');
    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Cliente', 'type' => 'customer', 'country' => 'IT']);
    $project = Project::factory()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $contact->id, 'project_manager_user_id' => $actor->id]);

    $this->postJson('/api/v1/workflows/instances', ['workflow_code' => 'X', 'approvable_type' => 'project', 'approvable_id' => $project->id])
        ->assertForbidden();
});

test('only the initiator or an administrator cancels an instance, and strangers do not read its audit trail', function (): void {
    $initiator = hardeningActor('member');
    $definition = WorkflowDefinition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'code' => 'GENERIC',
        'name' => 'Generic',
        'target_entity_type' => Invoice::class,
        'version' => 1,
        'status' => 'published',
        'is_active' => true,
    ]);
    $instance = WorkflowInstance::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'workflow_definition_id' => $definition->id,
        'workflow_definition_version' => 1,
        'approvable_type' => Invoice::class,
        'approvable_id' => (string) Str::uuid7(),
        'status' => 'pending',
        'created_by' => $initiator->id,
    ]);

    hardeningActor('member');
    $this->postJson("/api/v1/workflows/instances/{$instance->id}/cancel")->assertForbidden();
    $this->getJson("/api/v1/workflows/instances/{$instance->id}/audit-trail")->assertForbidden();

    Sanctum::actingAs($initiator);
    $this->getJson("/api/v1/workflows/instances/{$instance->id}/audit-trail")->assertOk();
});

test('the approval matrix reads the record before the client snapshot', function (): void {
    $matrix = new ApprovalMatrix;
    $matrix->setRelation('rules', collect([(new ApprovalMatrixRule)->forceFill(['field_name' => 'total_amount', 'operator' => '>', 'value_numeric' => 500, 'cost_center_id' => null])]));
    $matrix->setRelation('tiers', collect([(new ApprovalMatrixTier)->forceFill(['tier_level' => 1, 'threshold_min' => 500, 'threshold_max' => null, 'approver_type' => 'role', 'approver_value' => 'admin', 'approval_type' => 'single'])]));

    $entity = new class extends Model
    {
        protected $guarded = [];
    };
    $entity->forceFill(['total_amount' => '10000.00']);

    $tiers = app(ApprovalMatrixEvaluatorService::class)->evaluate($matrix, $entity, ['total_amount' => '1.00', 'amount' => '1.00']);

    expect($tiers)->toHaveCount(1)->and($tiers[0]['approver_value'])->toBe('admin');
});

// B18 ----------------------------------------------------------------- workflow roles from the membership

test('workflow definitions are managed by the role on the membership of the active tenant', function (): void {
    $user = hardeningActor('member');
    $user->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    app(TenantContext::class)->setTenant($this->tenant);

    // Administrator of another company, plain member here.
    expect(Gate::forUser($user)->allows('viewAny', WorkflowDefinition::class))->toBeFalse()
        ->and(Gate::forUser($user)->allows('create', WorkflowDefinition::class))->toBeFalse();

    $user->tenants()->updateExistingPivot($this->tenant->id, ['permissions' => json_encode(['workflows.view'])]);
    expect(Gate::forUser($user->fresh())->allows('viewAny', WorkflowDefinition::class))->toBeTrue()
        ->and(Gate::forUser($user->fresh())->allows('create', WorkflowDefinition::class))->toBeFalse();

    $user->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'admin']);
    expect(Gate::forUser($user->fresh())->allows('create', WorkflowDefinition::class))->toBeTrue();

    app(TenantContext::class)->clear();
});

// B5 ------------------------------------------------------------------ attendance reports

test('the monthly attendance report of a colleague is for HR, and only for members of the tenant', function (): void {
    $colleague = User::create(['name' => 'Collega', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $colleague->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    $stranger = User::create(['name' => 'Estraneo', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $stranger->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    $member = hardeningActor('member');
    $this->withHeader('X-User-Id', $member->id)->getJson("/api/v1/time-attendance/monthly-report?user_id={$colleague->id}")->assertForbidden();
    $this->withHeader('X-User-Id', $member->id)->getJson('/api/v1/time-attendance/monthly-report')->assertOk();

    $hr = hardeningActor('hr');
    $this->withHeader('X-User-Id', $hr->id)->getJson("/api/v1/time-attendance/monthly-report?user_id={$colleague->id}")->assertOk();
    $this->withHeader('X-User-Id', $hr->id)->getJson("/api/v1/time-attendance/monthly-report?user_id={$stranger->id}")->assertNotFound();
});

// B6 ------------------------------------------------------------------ attachments

test('attachments follow the module of the record they belong to', function (): void {
    Storage::fake(config('filesystems.private_disk', config('filesystems.default', 'local')));
    $invoice = hardeningInvoice($this->tenant);

    hardeningActor('member');
    $this->post('/api/v1/attachments', ['attachable_type' => 'invoice', 'attachable_id' => $invoice->id, 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertForbidden();

    hardeningActor('accountant');
    $id = $this->post('/api/v1/attachments', ['attachable_type' => 'invoice', 'attachable_id' => $invoice->id, 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');

    hardeningActor('member');
    $this->deleteJson("/api/v1/attachments/{$id}")->assertForbidden();
    expect(Attachment::whereKey($id)->exists())->toBeTrue();

    hardeningActor('accountant');
    $this->deleteJson("/api/v1/attachments/{$id}")->assertOk();
});

// B17 ----------------------------------------------------------------- document emails

test('emailing a sales document needs the invoicing write role', function (): void {
    $invoice = hardeningInvoice($this->tenant);

    hardeningActor('member');
    $this->postJson("/api/v1/documents/invoice/{$invoice->id}/email", ['to' => 'cliente@example.com'])->assertForbidden();

    hardeningActor('technician');
    $this->postJson("/api/v1/crm/contacts/{$invoice->contact_id}/statement/email", ['to' => 'cliente@example.com'])->assertForbidden();
});

// B7 ------------------------------------------------------------------ treasury

test('auto-match marks paid only the invoice whose number is exactly the reference', function (): void {
    $invoice = hardeningInvoice($this->tenant, 'INV-2026-100');
    $account = BankAccount::factory()->create(['tenant_id' => $this->tenant->id]);
    $transaction = BankTransaction::create([
        'tenant_id' => $this->tenant->id,
        'bank_account_id' => $account->id,
        'booking_date' => now()->toDateString(),
        'value_date' => now()->toDateString(),
        'amount' => 122,
        'currency' => 'EUR',
        'description' => 'Partial reference',
        'reference' => '100',
        'status' => 'unmatched',
    ]);

    hardeningActor('accountant');
    $this->postJson('/api/v1/treasury/reconciliation/auto-match')->assertOk()->assertJsonPath('data.matched_invoices', 0);

    expect($invoice->fresh()->payment_status)->toBe('unpaid')
        ->and($transaction->fresh()->status)->toBe('unmatched');
});

test('statements cannot be imported into the bank account of another tenant', function (): void {
    $foreignAccount = BankAccount::factory()->create(['tenant_id' => $this->otherTenant->id]);

    hardeningActor('accountant');
    $this->post('/api/v1/treasury/import-cbi', ['cbi_file' => UploadedFile::fake()->create('cbi.txt', 2), 'bank_account_id' => $foreignAccount->id], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('bank_account_id');
});

// B8 ------------------------------------------------------------------ ledger entries

test('a ledger entry cannot post to the accounts of another tenant', function (): void {
    $foreign = ChartOfAccount::factory()->create(['tenant_id' => $this->otherTenant->id]);

    hardeningActor('accountant');
    $this->postJson('/api/v1/accounting/ledger-entries', [
        'entry_number' => 'GL-1',
        'date' => now()->toDateString(),
        'description' => 'Test',
        'lines' => [
            ['account_id' => $foreign->id, 'debit' => 10, 'credit' => 0],
            ['account_id' => $foreign->id, 'debit' => 0, 'credit' => 10],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.account_id', 'lines.1.account_id']);
});

// B9 ------------------------------------------------------------------ references to other tenants

test('projects, assets and expenses refuse records and users of another tenant', function (): void {
    $stranger = User::create(['name' => 'Estraneo', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $stranger->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    $foreignContact = Contact::create(['tenant_id' => $this->otherTenant->id, 'company_name' => 'Altro', 'type' => 'customer', 'country' => 'IT']);

    hardeningActor('admin');

    $this->postJson('/api/v1/projects', ['contact_id' => $foreignContact->id, 'project_manager_user_id' => $stranger->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['contact_id', 'project_manager_user_id']);
    $this->postJson('/api/v1/eam/assets', ['custodian_user_id' => $stranger->id])
        ->assertUnprocessable()->assertJsonValidationErrors('custodian_user_id');
    $this->postJson('/api/v1/expenses', ['user_id' => $stranger->id])
        ->assertUnprocessable()->assertJsonValidationErrors('user_id');
});

// B10 ----------------------------------------------------------------- suspended tenants and disabled users

test('a suspended tenant and a deactivated user lose access', function (): void {
    $user = hardeningActor('admin');
    $this->getJson('/api/v1/settings/tenant')->assertOk();

    $this->tenant->update(['status' => 'suspended']);
    $this->getJson('/api/v1/settings/tenant')->assertForbidden();

    $this->tenant->update(['status' => 'active']);
    $user->forceFill(['is_active' => false])->save();
    $this->getJson('/api/v1/settings/tenant')->assertForbidden();
});

test('deactivating a user revokes every API token', function (): void {
    $user = User::create(['name' => 'u', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'is_active' => true]);
    $user->createToken('a');
    $user->createToken('b');

    $user->update(['is_active' => false]);

    expect($user->tokens()->count())->toBe(0);
});

// B11 ----------------------------------------------------------------- API keys

test('an API key never gets a scope its creator does not hold', function (): void {
    config(['permissions.modules.reporting.read' => ['accountant']]);

    hardeningActor('admin');
    $this->postJson('/api/v1/integrations/api-keys', ['name' => 'BI', 'scopes' => ['reporting.read']])
        ->assertUnprocessable()->assertJsonValidationErrors('scopes.0');

    hardeningActor('accountant');
    $this->postJson('/api/v1/integrations/api-keys', ['name' => 'BI', 'scopes' => ['reporting.read']])->assertForbidden();
});

// B12 ----------------------------------------------------------------- local payment driver

test('the local payment driver exists only in development and tests', function (): void {
    expect(PaymentGatewayResolver::providers())->toContain('local');

    app()->detectEnvironment(fn (): string => 'production');

    try {
        expect(PaymentGatewayResolver::providers())->not->toContain('local')
            ->and(fn () => app(PaymentGatewayResolver::class)->driver('local'))->toThrow(PaymentGatewayException::class);

        hardeningActor('admin');
        $this->putJson('/api/v1/integrations/payment-gateway', ['provider' => 'local', 'credentials' => ['webhook_secret' => 'x']])
            ->assertUnprocessable()->assertJsonValidationErrors('provider');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    hardeningActor('accountant');
    $this->putJson('/api/v1/integrations/payment-gateway', ['provider' => 'local'])->assertForbidden();
});

// B13 ----------------------------------------------------------------- customer portal

test('a portal link for one quote does not list the other documents of the customer', function (): void {
    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Villa', 'type' => 'customer', 'country' => 'IT', 'email' => 'villa@example.com']);
    $quote = Quote::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $contact->id,
        'quote_number' => 'QT-1',
        'date' => now()->toDateString(),
        'valid_until' => now()->addMonth()->toDateString(),
        'currency' => 'EUR',
        'total_net' => 100,
        'total_tax' => 22,
        'total_gross' => 122,
        'status' => 'sent',
    ]);

    $service = app(PortalTokenService::class);
    $scoped = $service->createToken($contact, 'dashboard', $quote)['token'];
    $full = $service->createToken($contact, 'dashboard')['token'];

    foreach (['invoices', 'service-reports', 'tickets', 'appointments'] as $list) {
        $this->getJson("/api/v1/portal/{$list}/{$scoped}")->assertForbidden();
    }

    $this->getJson("/api/v1/portal/invoices/{$full}")->assertOk();
});

// B14 ----------------------------------------------------------------- API documentation

test('the API documentation needs a platform administrator or a listed address when protected', function (): void {
    config(['docs.require_auth' => true, 'docs.allowed_emails' => ['reader@example.com']]);

    $this->getJson('/docs/api-docs.json')->assertUnauthorized();

    $member = User::create(['name' => 'm', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $this->actingAs($member, 'sanctum')->getJson('/docs/api-docs.json')->assertForbidden();

    $reader = User::create(['name' => 'r', 'email' => 'reader@example.com', 'password' => 'secret123']);
    expect($this->actingAs($reader, 'sanctum')->getJson('/docs/api-docs.json')->getStatusCode())->not->toBeIn([401, 403]);
});

test('the API documentation stays open where protection is off', function (): void {
    config(['docs.require_auth' => false]);

    expect($this->getJson('/docs/api-docs.json')->getStatusCode())->not->toBeIn([401, 403]);
});

// B15 ----------------------------------------------------------------- support bootstrap

test('the support bootstrap needs the secret in the header, in every environment', function (): void {
    config(['services.bootstrap.secret' => null]);
    $this->getJson('/api/v1/system/bootstrap-support')->assertForbidden();

    config(['services.bootstrap.secret' => 'boot-secret']);
    $this->getJson('/api/v1/system/bootstrap-support?secret=boot-secret')->assertForbidden();
    $this->withHeader('X-Bootstrap-Secret', 'wrong')->getJson('/api/v1/system/bootstrap-support')->assertForbidden();
    $this->withHeader('X-Bootstrap-Secret', 'boot-secret')->getJson('/api/v1/system/bootstrap-support')->assertSuccessful();
});

// B16 ----------------------------------------------------------------- login timing

test('a login with an unknown email still checks a password hash', function (): void {
    Hash::shouldReceive('make')->zeroOrMoreTimes()->andReturn(password_hash('dummy', PASSWORD_BCRYPT));
    Hash::shouldReceive('check')->once()->andReturnFalse();

    $this->withHeaders(spaHeaders())->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever'])->assertUnauthorized();
});
