<?php

declare(strict_types=1);

use App\Contracts\PdfGenerator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Modules\Associations\Enums\MembershipStatus;
use Modules\Associations\Jobs\ExpireMembershipsJob;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\Credential;
use Modules\Associations\Models\FeePlan;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Models\RegistryType;
use Modules\Associations\Services\CredentialService;
use Modules\Associations\Services\MembershipService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    actAs($this, 'secretary');
    $this->year = AssociativeYear::factory()->create();
    $this->type = MembershipType::factory()->create();
});

afterEach(fn () => associationsTearDown());

function openMembership(object $test, ?Member $member = null, bool $activate = true): array
{
    $member ??= Member::factory()->create();

    return $test->postJson('/api/v1/associations/memberships', [
        'member_id' => $member->id, 'year_id' => $test->year->id, 'membership_type_id' => $test->type->id, 'activate' => $activate,
    ])->assertCreated()->json('data');
}

test('activating gives a progressive card number per tenant, the registry type, the fee and a QR', function (): void {
    $registryType = RegistryType::factory()->create();
    $plan = FeePlan::factory()->create(['amount' => '50.00']);
    $this->type->update(['registry_type_id' => $registryType->id, 'fee_plan_id' => $plan->id]);
    AssociationSetting::current()->update(['card_prefix' => 'AX', 'card_padding' => 4]);
    $member = Member::factory()->create();

    $first = openMembership($this, $member);
    $second = openMembership($this);

    expect($first['card_number'])->toBe('AX0001')
        ->and($second['card_number'])->toBe('AX0002')
        ->and($first['status'])->toBe('active')
        ->and($member->registryAssignments()->where('registry_type_id', $registryType->id)->where('year_id', $this->year->id)->exists())->toBeTrue()
        ->and($member->feeAssignments()->where('fee_plan_id', $plan->id)->count())->toBe(1)
        ->and(app(CredentialService::class)->currentQr($member))->not->toBeNull();
});

test('the card sequence of one tenant does not touch another', function (): void {
    expect(openMembership($this)['card_number'])->toBe('000001');

    inTenant($this->otherTenant, function (): void {
        $year = AssociativeYear::factory()->create();
        $type = MembershipType::factory()->create();
        $membership = app(MembershipService::class)->request(Member::factory()->create(), $year, $type);

        expect(app(MembershipService::class)->activate($membership)->card_number)->toBe('000001');
    });
});

test('a renewal opens the next year with the same card number', function (): void {
    $membership = openMembership($this);
    $next = AssociativeYear::factory()->create(['starts_on' => now()->addYear()->startOfYear(), 'ends_on' => now()->addYear()->endOfYear()]);

    $renewed = $this->postJson("/api/v1/associations/memberships/{$membership['id']}/renew", ['year_id' => $next->id])->assertCreated()->json('data');

    expect($renewed['card_number'])->toBe($membership['card_number'])
        ->and($renewed['status'])->toBe('active')
        ->and($renewed['renewed_from_id'])->toBe($membership['id']);

    $this->postJson("/api/v1/associations/memberships/{$membership['id']}/renew", ['year_id' => $next->id])->assertConflict();
    $this->postJson("/api/v1/associations/memberships/{$membership['id']}/renew", ['year_id' => $this->year->id])->assertUnprocessable();
});

test('the QR carries a signed token and no personal data', function (): void {
    $member = Member::factory()->create(['tax_code' => 'RSSMRA85M01H501Q']);
    openMembership($this, $member);

    $credentials = app(CredentialService::class);
    $payload = $credentials->qrPayload($credentials->currentQr($member));
    $tampered = substr($payload, 0, -1).(str_ends_with($payload, '0') ? '1' : '0');

    expect($payload)->not->toContain($member->contact->last_name)->not->toContain('RSSMRA')->not->toContain($member->id)
        ->and(strlen($payload))->toBeLessThan(80)
        ->and($credentials->resolve($payload, $this->tenant->id)['reason'])->toBeNull()
        ->and($credentials->resolve($tampered, $this->tenant->id)['reason']->value)->toBe('invalid_signature');
});

test('a QR can be revoked, and issuing a new one retires the old one', function (): void {
    $member = Member::factory()->create();
    openMembership($this, $member);
    $credentials = app(CredentialService::class);
    $old = $credentials->qrPayload($credentials->currentQr($member));

    $this->postJson("/api/v1/associations/members/{$member->id}/qr")->assertCreated();

    expect($credentials->resolve($old, $this->tenant->id)['reason']->value)->toBe('credential_revoked');

    $current = $credentials->currentQr($member);
    $this->deleteJson("/api/v1/associations/credentials/{$current->id}")->assertNoContent();

    expect($credentials->resolve($credentials->qrPayload($current), $this->tenant->id)['reason']->value)->toBe('credential_revoked');
});

test('the digital card is a PDF, with the QR only of its holder', function (): void {
    $member = Member::factory()->create();
    $membership = openMembership($this, $member);

    $this->mock(PdfGenerator::class)->shouldReceive('render')->once()->withArgs(function (string $view, array $data): bool {
        return $view === 'associations::pdf.card' && str_starts_with((string) $data['qr'], 'data:image/png;base64,');
    })->andReturn('%PDF-fake');

    $this->get("/api/v1/associations/memberships/{$membership['id']}/card")->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('the real PDF of the card renders', function (): void {
    $member = Member::factory()->create();
    $membership = openMembership($this, $member);

    expect(substr((string) $this->get("/api/v1/associations/memberships/{$membership['id']}/card")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
});

test('suspending and ending a membership stop the credentials', function (): void {
    $member = Member::factory()->create();
    $membership = openMembership($this, $member);
    $credentials = app(CredentialService::class);
    $qr = $credentials->currentQr($member);

    $this->postJson("/api/v1/associations/memberships/{$membership['id']}/suspend", ['reason' => 'Unpaid'])->assertOk()->assertJsonPath('data.status', 'suspended');
    expect($qr->refresh()->revoked_at)->not->toBeNull();

    $this->postJson("/api/v1/associations/memberships/{$membership['id']}/activate")->assertOk()->assertJsonPath('data.status', 'active');
    expect($credentials->currentQr($member))->not->toBeNull();

    $this->postJson("/api/v1/associations/memberships/{$membership['id']}/cease", ['reason' => 'Resigned'])->assertOk()->assertJsonPath('data.status', 'ceased');
    expect($credentials->currentQr($member))->toBeNull();
});

test('a badge is registered once and never stored in clear', function (): void {
    $member = Member::factory()->create();

    $this->postJson("/api/v1/associations/members/{$member->id}/badges", ['uid' => 'ABC123456'])->assertCreated();
    $this->postJson("/api/v1/associations/members/{$member->id}/badges", ['uid' => 'abc123456'])->assertConflict();

    expect(Credential::query()->firstOrFail()->lookup_hash)->not->toContain('ABC123456')
        ->and(app(CredentialService::class)->resolve('ABC123456', $this->tenant->id)['credential']->member_id)->toBe($member->id);
});

test('memberships past their end expire with the daily job', function (): void {
    $membership = openMembership($this);
    Membership::query()->whereKey($membership['id'])->update(['valid_to' => now()->subDay()->toDateString()]);

    (new ExpireMembershipsJob($this->tenant->id))->handle(app(TenantContext::class), app(MembershipService::class));

    expect(Membership::query()->findOrFail($membership['id'])->status)->toBe(MembershipStatus::Expired);
});

test('the scheduled jobs of the module are registered per tenant', function (): void {
    Bus::fake();

    $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->description)->filter()->values();

    expect($events)->toContain('associations:fee-reminders', 'associations:renewal-reminders', 'associations:certificate-alerts', 'associations:expire-memberships');
});
