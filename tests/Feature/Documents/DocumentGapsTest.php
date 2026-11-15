<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Jobs\AnchorPreservationChainJob;
use Modules\Documents\Models\DmsPreservationChainLink;
use Modules\Documents\Services\PreservationChainService;
use Modules\DocumentTrust\Models\PreservationPackage;

uses(RefreshDatabase::class);

function dmgMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => Str::random(32)]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function dmgActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.private_disk' => 'local']);
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->admin = dmgMember($this->tenant, 'admin');
    $this->salesManager = dmgMember($this->tenant, 'sales_manager');
    $this->member = dmgMember($this->tenant, 'member');
    dmgActAs($this->admin, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a sales manager named as approver can decide a step', function (): void {
    $template = $this->postJson('/api/v1/dms/approval-templates', ['name' => 'Sales', 'steps' => [
        ['name' => 'Sales lead', 'approver_type' => 'role', 'approver_role' => 'sales_manager'],
    ]])->assertCreated()->json('data.id');
    $id = $this->post('/api/v1/dms/documents', [
        'title' => 'Offer',
        'file' => UploadedFile::fake()->createWithContent('offer.txt', 'Offer text'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    dmgActAs($this->member, $this->tenant);
    $request = $this->postJson("/api/v1/dms/documents/{$id}/approval-requests", ['template_id' => $template])->assertCreated()->json('data.id');

    dmgActAs($this->salesManager, $this->tenant);
    $this->postJson("/api/v1/dms/approval-requests/{$request}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
});

test('an approval step cannot name a role without document write access', function (): void {
    $this->postJson('/api/v1/dms/approval-templates', ['name' => 'Bad', 'steps' => [
        ['name' => 'Ghost', 'approver_type' => 'role', 'approver_role' => 'accountant_readonly'],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['steps.0.approver_role']);
});

test('the scheduled job anchors closed packages once for its own company', function (): void {
    $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);

    foreach ([$this->tenant, $other] as $tenant) {
        PreservationPackage::create([
            'tenant_id' => $tenant->id,
            'document_class' => 'issued_invoice',
            'name' => 'Package '.Str::random(4),
            'status' => PreservationPackage::Closed,
            'package_hash' => hash('sha256', Str::random(16)),
            'closed_at' => now()->subDay(),
        ]);
    }

    app(TenantContext::class)->clear();
    (new AnchorPreservationChainJob($this->tenant->id))->handle(app(TenantContext::class), app(PreservationChainService::class));

    expect(DmsPreservationChainLink::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(DmsPreservationChainLink::withoutGlobalScopes()->where('tenant_id', $other->id)->count())->toBe(0);

    $second = (new AnchorPreservationChainJob($this->tenant->id))->handle(app(TenantContext::class), app(PreservationChainService::class));
    expect($second)->toBe(0);
});
