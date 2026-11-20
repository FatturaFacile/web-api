<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Database\Seeders\AcademyContextualHelpSeeder;
use Database\Seeders\AcademyLearningContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Academy\Models\AcademyCertificate;
use Modules\Academy\Models\AcademyLearningTrack;
use Modules\Academy\Models\InteractiveMission;
use Modules\Academy\Models\UserAcademyProgress;
use Tests\Support\AcademyScenario;
use Tests\Support\CopilotScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AcademyLearningContentSeeder::class);
    $this->seed(AcademyContextualHelpSeeder::class);
    $this->sandbox = AcademyScenario::tenant('Sandbox', true);
    $this->production = AcademyScenario::tenant('Production', false);
    $this->learner = CopilotScenario::member($this->sandbox, 'member');
    CopilotScenario::actAs($this, $this->learner, $this->sandbox);
    $this->track = AcademyLearningTrack::query()->where('code', 'swiss-accounting-kmu')->firstOrFail();
});

afterEach(fn () => app(TenantContext::class)->clear());

function academyMission(string $code): InteractiveMission
{
    return InteractiveMission::query()->where('code', $code)->firstOrFail();
}

test('the seeded catalogue has the five tracks, each with missions and all five locales', function (): void {
    expect(AcademyLearningTrack::query()->count())->toBe(5);

    foreach (AcademyLearningTrack::query()->with('missions')->get() as $track) {
        expect($track->missions)->not->toBeEmpty()
            ->and(array_keys($track->translations_json))->toEqualCanonicalizing(['it-CH', 'it-IT', 'de-CH', 'fr-CH', 'en']);
    }
});

test('a mission that needs a sandbox cannot be started in a production tenant', function (): void {
    CopilotScenario::actAs($this, CopilotScenario::member($this->production, 'member'), $this->production);

    $this->postJson('/api/v1/academy/missions/'.academyMission('ch-post-first-journal')->id.'/start')->assertUnprocessable();

    expect(UserAcademyProgress::withoutGlobalScopes()->count())->toBe(0);
});

test('starting a mission in a sandbox tenant is idempotent', function (): void {
    $id = academyMission('ch-post-first-journal')->id;

    $this->postJson("/api/v1/academy/missions/{$id}/start")->assertCreated()->assertJsonPath('data.status', 'started');
    $this->postJson("/api/v1/academy/missions/{$id}/start")->assertCreated();

    expect(UserAcademyProgress::query()->count())->toBe(1);
});

test('an ERP event completes a started mission in a sandbox tenant and awards XP and the badge', function (): void {
    $mission = academyMission('ch-post-first-journal');
    $this->postJson("/api/v1/academy/missions/{$mission->id}/start")->assertCreated();

    AcademyScenario::raise($this->sandbox, 'general_ledger.journal.posted');

    $this->postJson("/api/v1/academy/missions/{$mission->id}/verify-step")
        ->assertOk()
        ->assertJsonPath('data.completed', true)
        ->assertJsonPath('data.xp_awarded', 100)
        ->assertJsonPath('data.badge_code', 'first-journal');

    $this->getJson('/api/v1/academy/progress')->assertOk()->assertJsonPath('data.total_xp', 100)->assertJsonPath('data.badges.0', 'first-journal');
});

test('events of a production tenant never advance a mission', function (): void {
    $mission = academyMission('ch-post-first-journal');
    UserAcademyProgress::query()->create([
        'tenant_id' => $this->production->id, 'user_id' => $this->learner->id, 'track_id' => $mission->track_id,
        'mission_id' => $mission->id, 'status' => UserAcademyProgress::Started, 'started_at' => now(),
    ]);

    AcademyScenario::raise($this->production, 'general_ledger.journal.posted');

    $row = UserAcademyProgress::withoutGlobalScopes()->where('tenant_id', $this->production->id)->firstOrFail();
    expect($row->status)->toBe(UserAcademyProgress::Started)->and($row->xp_awarded)->toBe(0);
});

test('an event of another type or a predicate that does not hold leaves the mission started', function (): void {
    $mission = academyMission('ch-issue-first-invoice');
    $this->postJson("/api/v1/academy/missions/{$mission->id}/start")->assertCreated();

    AcademyScenario::raise($this->sandbox, 'general_ledger.journal.posted');

    $this->postJson("/api/v1/academy/missions/{$mission->id}/verify-step")->assertJsonPath('data.completed', false);

    $mission->forceFill(['verification_rule_json' => ['target_event' => 'invoicing.invoice.posted', 'predicate' => 'total_gross >= 100', 'sandbox_only' => true]])->save();
    AcademyScenario::raise($this->sandbox, 'invoicing.invoice.posted', ['total_gross' => 40]);
    $this->postJson("/api/v1/academy/missions/{$mission->id}/verify-step")->assertJsonPath('data.completed', false);

    AcademyScenario::raise($this->sandbox, 'invoicing.invoice.posted', ['total_gross' => 140]);
    $this->postJson("/api/v1/academy/missions/{$mission->id}/verify-step")->assertJsonPath('data.completed', true);
});

test('completing the last mission of a track issues a verifiable certificate with a PDF and QR code', function (): void {
    foreach (['ch-post-first-journal' => 'general_ledger.journal.posted', 'ch-issue-first-invoice' => 'invoicing.invoice.posted', 'ch-reconcile-bank' => 'treasury.bank_reconciliation.completed'] as $code => $event) {
        $this->postJson('/api/v1/academy/missions/'.academyMission($code)->id.'/start')->assertCreated();
        expect(AcademyCertificate::query()->count())->toBe(0);
        AcademyScenario::raise($this->sandbox, $event);
    }

    $certificate = AcademyCertificate::query()->firstOrFail();
    expect($certificate->total_xp)->toBe(350)->and($certificate->holder_name)->toBe($this->learner->name);

    $this->getJson("/api/v1/academy/tracks/{$this->track->id}?locale=en")
        ->assertOk()
        ->assertJsonPath('data.completion_percent', 100)
        ->assertJsonPath('data.certificate_code', $certificate->certificate_code);

    $pdf = $this->get("/api/v1/academy/tracks/{$this->track->id}/certificate?locale=de-CH");
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();

    // Public verification: no authentication, no tenant header.
    app(TenantContext::class)->clear();
    $this->app['auth']->forgetGuards();
    $this->withoutHeader('X-Tenant-Id')->getJson('/api/v1/academy/certificates/verify/'.$certificate->certificate_code)
        ->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.holder_name', $this->learner->name)
        ->assertJsonPath('data.track_code', 'swiss-accounting-kmu')
        ->assertJsonMissingPath('data.tenant_id')
        ->assertJsonMissingPath('data.user_id');

    $this->getJson('/api/v1/academy/certificates/verify/ACA-UNKNOWN')->assertNotFound();
});

test('progress and certificates are isolated between tenants', function (): void {
    $mission = academyMission('ch-post-first-journal');
    $this->postJson("/api/v1/academy/missions/{$mission->id}/start")->assertCreated();
    AcademyScenario::raise($this->sandbox, 'general_ledger.journal.posted');

    $otherSandbox = AcademyScenario::tenant('Other sandbox', true);
    CopilotScenario::actAs($this, CopilotScenario::member($otherSandbox, 'member'), $otherSandbox);

    $this->getJson('/api/v1/academy/progress')->assertOk()->assertJsonPath('data.total_xp', 0);
    $this->postJson("/api/v1/academy/missions/{$mission->id}/verify-step")->assertNotFound();

    // The same event in the other tenant does not complete the mission of the first one.
    AcademyScenario::raise($otherSandbox, 'general_ledger.journal.posted');
    expect(UserAcademyProgress::withoutGlobalScopes()->where('tenant_id', $this->sandbox->id)->where('status', 'completed')->count())->toBe(1)
        ->and(UserAcademyProgress::withoutGlobalScopes()->where('tenant_id', $otherSandbox->id)->count())->toBe(0);
});
