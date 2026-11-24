<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\DmsDocument;
use Modules\Manufacturing\Models\MfgQualityNonConformance;
use Modules\Quality\Models\QmsCapaAction;
use Modules\Quality\Models\QmsNonconformity;
use Modules\Quality\Models\QmsProcedure;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-05-12 09:00:00');
    $this->tenant = qmsTenant('Qualita Rossi');
    $this->owner = qmsMember($this->tenant, 'owner');
    $this->production = qmsMember($this->tenant, 'production');
    $this->reader = qmsMember($this->tenant, 'sales');
    qmsAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function qmsTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function qmsMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@qms-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function qmsAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function qmsNonconformity(array $overrides = []): array
{
    return test()->postJson('/api/v1/quality/nonconformities', [
        'title' => 'Lotto di viti fuori tolleranza',
        'source' => 'supplier',
        'severity' => 'minor',
        ...$overrides,
    ])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function qmsCapa(string $nonconformityId, string $ownerId, array $overrides = []): array
{
    return test()->postJson('/api/v1/quality/capa-actions', [
        'nonconformity_id' => $nonconformityId,
        'title' => 'Sostituire il fornitore del lotto',
        'owner_id' => $ownerId,
        'due_date' => '2027-06-12',
        ...$overrides,
    ])->assertCreated()->json('data');
}

test('non-conformities are numbered per year, filtered and come from every source', function (): void {
    $supplier = qmsNonconformity(['source' => 'supplier']);
    $complaint = qmsNonconformity(['source' => 'customer_complaint', 'severity' => 'major']);
    qmsNonconformity(['source' => 'internal']);

    expect($supplier['number'])->toBe('NC-2027-0001')
        ->and($complaint['number'])->toBe('NC-2027-0002')
        ->and($supplier['status'])->toBe('open');

    expect($this->getJson('/api/v1/quality/nonconformities?source=customer_complaint')->assertOk()->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/quality/nonconformities?severity=minor&open=1')->assertOk()->json('data'))->toHaveCount(2);

    $this->postJson('/api/v1/quality/nonconformities', ['title' => 'x', 'source' => 'unknown'])->assertUnprocessable();
});

test('a manufacturing non-conformance is referenced by id and never changed', function (): void {
    $mfg = MfgQualityNonConformance::create(['tenant_id' => $this->tenant->id, 'nc_number' => 'MNC-1', 'severity' => 'major', 'defect_type' => 'scratch', 'description' => 'Graffio', 'disposition' => 'investigating', 'status' => 'open', 'quantity' => '3']);

    $nc = qmsNonconformity(['source' => 'manufacturing', 'mfg_non_conformance_id' => $mfg->id]);
    expect($nc['mfg_non_conformance_id'])->toBe($mfg->id)
        ->and($mfg->refresh()->status)->toBe('open');

    // only the source manufacturing accepts the reference, and it must exist in the company
    $this->postJson('/api/v1/quality/nonconformities', ['title' => 'x', 'source' => 'internal', 'mfg_non_conformance_id' => $mfg->id])->assertUnprocessable();
    $this->postJson('/api/v1/quality/nonconformities', ['title' => 'x', 'source' => 'manufacturing', 'mfg_non_conformance_id' => (string) Str::uuid7()])->assertUnprocessable();
});

test('a CAPA action needs an owner and a due date, moves the non-conformity forward and blocks its closure until verified', function (): void {
    $nc = qmsNonconformity();
    $this->postJson('/api/v1/quality/capa-actions', ['nonconformity_id' => $nc['id'], 'title' => 'Senza responsabile'])->assertUnprocessable();
    $this->postJson('/api/v1/quality/capa-actions', ['nonconformity_id' => $nc['id'], 'title' => 'Owner estraneo', 'owner_id' => (string) Str::uuid7(), 'due_date' => '2027-06-12'])->assertUnprocessable();

    $action = qmsCapa($nc['id'], $this->production->id);
    expect($action['number'])->toBe('CAPA-2027-0001')
        ->and($action['status'])->toBe('open')
        ->and($this->getJson("/api/v1/quality/nonconformities/{$nc['id']}")->json('data.status'))->toBe('capa_in_progress');

    $this->postJson("/api/v1/quality/nonconformities/{$nc['id']}/close")->assertUnprocessable();

    $this->postJson("/api/v1/quality/capa-actions/{$action['id']}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
    // the effectiveness cannot be checked before the action is completed
    $this->postJson("/api/v1/quality/capa-actions/{$action['id']}/verify-effectiveness", ['result' => 'effective'])->assertUnprocessable();
    $this->postJson("/api/v1/quality/capa-actions/{$action['id']}/complete", ['effectiveness_check_due_date' => '2027-08-01'])->assertOk()->assertJsonPath('data.status', 'completed');
    $this->postJson("/api/v1/quality/nonconformities/{$nc['id']}/close")->assertUnprocessable();

    $this->postJson("/api/v1/quality/capa-actions/{$action['id']}/verify-effectiveness", ['result' => 'effective', 'notes' => 'Nessun difetto nei 3 lotti successivi'])
        ->assertOk()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.effectiveness_result', 'effective');

    $closed = $this->postJson("/api/v1/quality/nonconformities/{$nc['id']}/close", ['closure_notes' => 'Fornitore sostituito'])->assertOk()->json('data');
    expect($closed['status'])->toBe('closed')->and($closed['closed_at'])->not->toBeNull();

    // a closed non-conformity takes no new action
    $this->postJson('/api/v1/quality/capa-actions', ['nonconformity_id' => $nc['id'], 'title' => 'Tardiva', 'owner_id' => $this->production->id, 'due_date' => '2027-07-01'])->assertUnprocessable();
});

test('an ineffective action blocks the closure and a major non-conformity needs a root cause and a verified corrective action', function (): void {
    $nc = qmsNonconformity(['severity' => 'major']);
    $action = qmsCapa($nc['id'], $this->production->id);
    $this->postJson("/api/v1/quality/capa-actions/{$action['id']}/complete")->assertOk();
    $this->postJson("/api/v1/quality/capa-actions/{$action['id']}/verify-effectiveness", ['result' => 'not_effective'])->assertOk()->assertJsonPath('data.status', 'ineffective');

    $this->postJson("/api/v1/quality/nonconformities/{$nc['id']}/close")->assertUnprocessable();

    // a follow-up action; the ineffective one is cancelled
    $this->postJson("/api/v1/quality/capa-actions/{$action['id']}/cancel")->assertOk();
    $followUp = qmsCapa($nc['id'], $this->production->id, ['title' => 'Azione di seguito']);
    $this->postJson("/api/v1/quality/capa-actions/{$followUp['id']}/complete")->assertOk();
    $this->postJson("/api/v1/quality/capa-actions/{$followUp['id']}/verify-effectiveness", ['result' => 'effective'])->assertOk();

    // still no root cause
    $this->postJson("/api/v1/quality/nonconformities/{$nc['id']}/close")->assertUnprocessable()->assertJsonValidationErrors('root_cause');
    $this->putJson("/api/v1/quality/nonconformities/{$nc['id']}", ['root_cause' => 'Taratura della macchina del fornitore'])->assertOk();
    $this->postJson("/api/v1/quality/nonconformities/{$nc['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');
});

test('a preventive action can stand alone and a cancelled non-conformity cannot change', function (): void {
    $standalone = test()->postJson('/api/v1/quality/capa-actions', ['type' => 'preventive', 'title' => 'Formazione annuale', 'owner_id' => $this->production->id, 'due_date' => '2027-09-01'])->assertCreated()->json('data');
    expect($standalone['nonconformity_id'])->toBeNull()->and($standalone['type'])->toBe('preventive');

    $nc = qmsNonconformity();
    $this->postJson("/api/v1/quality/nonconformities/{$nc['id']}/cancel", [])->assertUnprocessable();
    $this->postJson("/api/v1/quality/nonconformities/{$nc['id']}/cancel", ['reason' => 'Aperta per errore'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->putJson("/api/v1/quality/nonconformities/{$nc['id']}", ['title' => 'Nuovo titolo'])->assertUnprocessable();
});

test('an internal audit runs through its checklist, raises non-conformities from findings and completes', function (): void {
    $audit = $this->postJson('/api/v1/quality/audits', [
        'title' => 'Audit processo acquisti',
        'planned_date' => '2027-06-01',
        'lead_auditor_id' => $this->owner->id,
        'checklist' => [['clause' => '8.4', 'requirement' => 'I fornitori sono valutati'], ['clause' => '8.5', 'requirement' => 'La produzione e tracciata']],
    ])->assertCreated()->json('data');

    expect($audit['number'])->toBe('AUD-2027-0001')->and($audit['checklist'])->toHaveCount(2)->and($audit['status'])->toBe('planned');
    [$first, $second] = $audit['checklist'];

    // nothing is recorded before the audit starts
    $this->putJson("/api/v1/quality/audits/{$audit['id']}/checklist/{$first['id']}", ['result' => 'conforming'])->assertUnprocessable();
    $this->postJson("/api/v1/quality/audits/{$audit['id']}/findings", ['classification' => 'observation', 'description' => 'x'])->assertUnprocessable();
    $this->postJson("/api/v1/quality/audits/{$audit['id']}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');

    $this->postJson("/api/v1/quality/audits/{$audit['id']}/complete")->assertUnprocessable();
    $this->putJson("/api/v1/quality/audits/{$audit['id']}/checklist/{$first['id']}", ['result' => 'conforming'])->assertOk();

    $finding = $this->postJson("/api/v1/quality/audits/{$audit['id']}/findings", ['classification' => 'major_nc', 'description' => 'Nessuna valutazione dei fornitori critici', 'checklist_item_id' => $second['id']])
        ->assertCreated()->json('data');
    expect($finding['number'])->toBe('FND-2027-0001')->and($finding['nonconformity_id'])->not->toBeNull();

    $nc = $this->getJson("/api/v1/quality/nonconformities/{$finding['nonconformity_id']}")->assertOk()->json('data');
    expect($nc['source'])->toBe('audit')->and($nc['severity'])->toBe('major')->and($nc['audit_finding_id'])->toBe($finding['id']);

    $observation = $this->postJson("/api/v1/quality/audits/{$audit['id']}/findings", ['classification' => 'observation', 'description' => 'Registro non aggiornato'])->assertCreated()->json('data');
    expect($observation['nonconformity_id'])->toBeNull();

    $shown = $this->getJson("/api/v1/quality/audits/{$audit['id']}")->assertOk()->json('data');
    expect(collect($shown['checklist'])->pluck('result')->all())->toBe(['conforming', 'nonconforming'])
        ->and($shown['findings'])->toHaveCount(2);

    $this->postJson("/api/v1/quality/audits/{$audit['id']}/complete", ['summary' => 'Un rilievo maggiore'])->assertOk()->assertJsonPath('data.status', 'completed');
});

test('a controlled procedure references a document by id and is approved by someone other than its author', function (): void {
    $document = DmsDocument::create(['tenant_id' => $this->tenant->id, 'title' => 'Procedura acquisti', 'status' => 'draft', 'current_version' => 1, 'access_roles' => [], 'metadata' => [], 'search_text' => '', 'legal_hold' => false]);

    $this->postJson('/api/v1/quality/procedures', ['code' => 'PRO-001', 'title' => 'Acquisti', 'document_id' => (string) Str::uuid7()])->assertUnprocessable();
    $procedure = $this->postJson('/api/v1/quality/procedures', ['code' => 'PRO-001', 'title' => 'Acquisti', 'review_due_date' => '2028-05-12'])->assertCreated()->json('data');
    expect($procedure['status'])->toBe('draft')->and($procedure['version'])->toBe(1)->and($procedure['author_id'])->toBe($this->owner->id);

    // the document must be linked before approval, and a code is unique
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/submit")->assertUnprocessable();
    $this->postJson('/api/v1/quality/procedures', ['code' => 'PRO-001', 'title' => 'Duplicata'])->assertUnprocessable();
    $this->putJson("/api/v1/quality/procedures/{$procedure['id']}", ['document_id' => $document->id])->assertOk()->assertJsonPath('data.document_id', $document->id);
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');

    // the author cannot approve; a role without approval rights cannot either
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/approve")->assertUnprocessable();
    qmsAct($this->production, $this->tenant);
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/approve")->assertForbidden();

    $admin = qmsMember($this->tenant, 'admin');
    qmsAct($admin, $this->tenant);
    $approved = $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/approve")->assertOk()->json('data');
    expect($approved['status'])->toBe('approved')->and($approved['approved_by'])->toBe($admin->id)->and($approved['effective_date'])->toBe('2027-05-12');

    // a revision is a new draft; approving it makes the previous version obsolete
    qmsAct($this->owner, $this->tenant);
    $v2 = $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/revise")->assertCreated()->json('data');
    expect($v2['version'])->toBe(2)->and($v2['status'])->toBe('draft');
    $this->postJson("/api/v1/quality/procedures/{$v2['id']}/submit")->assertOk();
    $this->postJson("/api/v1/quality/procedures/{$v2['id']}/approve")->assertUnprocessable(); // the owner revised it, so the owner is the author
    qmsAct($admin, $this->tenant);
    $this->postJson("/api/v1/quality/procedures/{$v2['id']}/approve")->assertOk();

    expect(QmsProcedure::query()->where('code', 'PRO-001')->orderBy('version')->pluck('status')->all())->toBe(['obsolete', 'approved'])
        ->and($this->getJson('/api/v1/quality/procedures?effective=1')->assertOk()->json('data'))->toHaveCount(1);
});

test('a rejected procedure goes back to its author with the reason and can be submitted again', function (): void {
    $document = DmsDocument::create(['tenant_id' => $this->tenant->id, 'title' => 'Procedura', 'status' => 'draft', 'current_version' => 1, 'access_roles' => [], 'metadata' => [], 'search_text' => '', 'legal_hold' => false]);
    $procedure = $this->postJson('/api/v1/quality/procedures', ['code' => 'PRO-002', 'title' => 'Collaudo', 'document_id' => $document->id])->assertCreated()->json('data');
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/submit")->assertOk();

    $admin = qmsMember($this->tenant, 'admin');
    qmsAct($admin, $this->tenant);
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/reject", [])->assertUnprocessable();
    $rejected = $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/reject", ['reason' => 'Manca il piano di campionamento'])->assertOk()->json('data');
    expect($rejected['status'])->toBe('rejected')->and($rejected['rejection_reason'])->toBe('Manca il piano di campionamento');

    qmsAct($this->owner, $this->tenant);
    $this->putJson("/api/v1/quality/procedures/{$procedure['id']}", ['title' => 'Collaudo in accettazione'])->assertOk();
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');
});

test('the KPIs count open non-conformities by age and CAPA on time', function (): void {
    qmsNonconformity(['detected_at' => '2027-05-10', 'source' => 'supplier']);
    qmsNonconformity(['detected_at' => '2027-04-01', 'source' => 'internal', 'severity' => 'major']);
    qmsNonconformity(['detected_at' => '2027-03-01', 'source' => 'internal']);
    qmsNonconformity(['detected_at' => '2026-12-01', 'source' => 'audit']);
    $closed = qmsNonconformity(['detected_at' => '2027-01-01']);
    $this->postJson("/api/v1/quality/nonconformities/{$closed['id']}/cancel", ['reason' => 'duplicata'])->assertOk();

    // on time: completed on its due date; late: completed after; overdue: still open past its due date; on time and open: not counted as overdue
    $withAction = qmsNonconformity(['detected_at' => '2027-05-11']);
    $onTime = qmsCapa($withAction['id'], $this->production->id, ['due_date' => '2027-05-12']);
    $this->postJson("/api/v1/quality/capa-actions/{$onTime['id']}/complete")->assertOk();
    $late = test()->postJson('/api/v1/quality/capa-actions', ['type' => 'preventive', 'title' => 'In ritardo', 'owner_id' => $this->production->id, 'due_date' => '2027-05-01'])->assertCreated()->json('data');
    $this->postJson("/api/v1/quality/capa-actions/{$late['id']}/complete")->assertOk();
    test()->postJson('/api/v1/quality/capa-actions', ['type' => 'preventive', 'title' => 'Scaduta', 'owner_id' => $this->production->id, 'due_date' => '2027-05-05'])->assertCreated();
    test()->postJson('/api/v1/quality/capa-actions', ['type' => 'preventive', 'title' => 'Futura', 'owner_id' => $this->production->id, 'due_date' => '2027-07-05'])->assertCreated();

    $kpis = $this->getJson('/api/v1/quality/kpis')->assertOk()->json('data');

    expect($kpis['open_nonconformities']['total'])->toBe(5)
        ->and($kpis['open_nonconformities']['by_age'])->toBe(['0-30' => 2, '31-60' => 1, '61-90' => 1, '90+' => 1])
        ->and($kpis['open_nonconformities']['by_source']['internal'])->toBe(2)
        ->and($kpis['open_nonconformities']['oldest_days'])->toBe(162)
        ->and($kpis['capa']['completed'])->toBe(2)
        ->and($kpis['capa']['completed_on_time'])->toBe(1)
        ->and($kpis['capa']['completed_late'])->toBe(1)
        ->and($kpis['capa']['overdue'])->toBe(1)
        ->and($kpis['capa']['on_time_rate'])->toEqual(33.3);
});

test('every domain change is written to the audit ledger', function (): void {
    $nc = qmsNonconformity();
    $action = qmsCapa($nc['id'], $this->production->id);
    $this->postJson("/api/v1/quality/capa-actions/{$action['id']}/complete")->assertOk();
    $this->postJson("/api/v1/quality/capa-actions/{$action['id']}/verify-effectiveness", ['result' => 'effective'])->assertOk();
    $this->postJson("/api/v1/quality/nonconformities/{$nc['id']}/close")->assertOk();

    $actions = AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->where('module_code', 'MODULE_40_QUALITY')->pluck('action')->all();
    expect($actions)->toContain('QMS_NONCONFORMITY_RAISED', 'QMS_CAPA_RAISED', 'QMS_CAPA_COMPLETED', 'QMS_CAPA_EFFECTIVENESS_CHECKED', 'QMS_NONCONFORMITY_CLOSED');
});

test('permissions: every member reads, only writing roles change, and the roles are enforced per company', function (): void {
    $nc = qmsNonconformity();

    qmsAct($this->reader, $this->tenant);
    $this->getJson('/api/v1/quality/nonconformities')->assertOk();
    $this->getJson('/api/v1/quality/kpis')->assertOk();
    $this->postJson('/api/v1/quality/nonconformities', ['title' => 'x', 'source' => 'internal'])->assertForbidden();
    $this->postJson("/api/v1/quality/nonconformities/{$nc['id']}/close")->assertForbidden();

    // a user of another company does not belong here at all
    $otherTenant = qmsTenant('Altra Srl');
    $outsider = qmsMember($otherTenant, 'owner');
    Sanctum::actingAs($outsider);
    app(TenantContext::class)->clear();
    $this->withHeaders(['X-Tenant-Id' => (string) $this->tenant->id])->getJson('/api/v1/quality/nonconformities')->assertForbidden();
});

test('tenant isolation: records, numbers, KPIs and references never cross companies', function (): void {
    $nc = qmsNonconformity();
    $action = qmsCapa($nc['id'], $this->production->id);
    $audit = $this->postJson('/api/v1/quality/audits', ['title' => 'Audit', 'planned_date' => '2027-06-01', 'checklist' => [['requirement' => 'R']]])->assertCreated()->json('data');
    $document = DmsDocument::create(['tenant_id' => $this->tenant->id, 'title' => 'Procedura', 'status' => 'draft', 'current_version' => 1, 'access_roles' => [], 'metadata' => [], 'search_text' => '', 'legal_hold' => false]);
    $procedure = $this->postJson('/api/v1/quality/procedures', ['code' => 'PRO-9', 'title' => 'Procedura', 'document_id' => $document->id])->assertCreated()->json('data');

    $otherTenant = qmsTenant('Concorrente Srl');
    $intruder = qmsMember($otherTenant, 'owner');
    $ownUser = qmsMember($otherTenant, 'production');
    qmsAct($intruder, $otherTenant);

    // nothing is listed and nothing can be read, changed or driven by id
    foreach (['nonconformities', 'capa-actions', 'audits', 'procedures'] as $collection) {
        expect($this->getJson("/api/v1/quality/{$collection}")->assertOk()->json('data'))->toBeEmpty();
    }

    $this->getJson("/api/v1/quality/nonconformities/{$nc['id']}")->assertNotFound();
    $this->putJson("/api/v1/quality/nonconformities/{$nc['id']}", ['title' => 'Hacked'])->assertNotFound();
    $this->postJson("/api/v1/quality/nonconformities/{$nc['id']}/close")->assertNotFound();
    $this->getJson("/api/v1/quality/capa-actions/{$action['id']}")->assertNotFound();
    $this->postJson("/api/v1/quality/capa-actions/{$action['id']}/complete")->assertNotFound();
    $this->getJson("/api/v1/quality/audits/{$audit['id']}")->assertNotFound();
    $this->postJson("/api/v1/quality/audits/{$audit['id']}/start")->assertNotFound();
    $this->putJson("/api/v1/quality/audits/{$audit['id']}/checklist/{$audit['checklist'][0]['id']}", ['result' => 'conforming'])->assertNotFound();
    $this->getJson("/api/v1/quality/procedures/{$procedure['id']}")->assertNotFound();
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/approve")->assertNotFound();

    // references to records of another company are refused: its non-conformities, documents and users
    $this->postJson('/api/v1/quality/capa-actions', ['nonconformity_id' => $nc['id'], 'title' => 'Intrusa', 'owner_id' => $ownUser->id, 'due_date' => '2027-06-12'])->assertNotFound();
    $this->postJson('/api/v1/quality/capa-actions', ['title' => 'Owner altrui', 'owner_id' => $this->production->id, 'due_date' => '2027-06-12'])->assertUnprocessable();
    $this->postJson('/api/v1/quality/procedures', ['code' => 'PRO-X', 'title' => 'Documento altrui', 'document_id' => $document->id])->assertUnprocessable();

    // numbers and KPIs are per company
    $mine = qmsNonconformity(['title' => 'Mia']);
    expect($mine['number'])->toBe('NC-2027-0001');
    $kpis = $this->getJson('/api/v1/quality/kpis')->assertOk()->json('data');
    expect($kpis['open_nonconformities']['total'])->toBe(1)->and($kpis['capa']['total'])->toBe(0)->and($kpis['audits']['planned'])->toBe(0);

    expect(QmsNonconformity::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->value('status'))->toBe('capa_in_progress')
        ->and(QmsCapaAction::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->value('status'))->toBe('open');
});
