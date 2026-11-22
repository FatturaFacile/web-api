<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/ResourcePlanningTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    rpDeferForeignKeys();
});

it('manages the skill taxonomy', function (): void {
    $tenant = rpTenant();
    rpActAs($tenant, rpUser($tenant));

    $id = $this->postJson('/api/v1/resource-planning/skills', ['category' => 'technical', 'skill_name' => 'PLC programming'])
        ->assertCreated()
        ->assertJsonPath('data.skill_name', 'PLC programming')
        ->assertJsonPath('data.requires_certification', false)
        ->json('data.id');

    $this->postJson('/api/v1/resource-planning/skills', ['category' => 'technical', 'skill_name' => 'PLC programming'])->assertUnprocessable();
    $this->postJson('/api/v1/resource-planning/skills', ['category' => 'safety', 'skill_name' => 'PLC programming'])->assertCreated();

    $this->putJson("/api/v1/resource-planning/skills/{$id}", ['category' => 'technical', 'skill_name' => 'PLC and SCADA', 'requires_certification' => true, 'certification_code' => 'forklift_patentino_muletto'])
        ->assertOk()
        ->assertJsonPath('data.skill_name', 'PLC and SCADA')
        ->assertJsonPath('data.requires_certification', true)
        ->assertJsonPath('data.certification_code', 'forklift_patentino_muletto');

    expect($this->getJson('/api/v1/resource-planning/skills?category=technical')->assertOk()->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/v1/resource-planning/skills')->assertOk()->json('data'))->toHaveCount(2);

    $this->deleteJson("/api/v1/resource-planning/skills/{$id}")->assertNoContent();
    $this->deleteJson("/api/v1/resource-planning/skills/{$id}")->assertNotFound();

    // The name of a deleted skill can be used again.
    $this->postJson('/api/v1/resource-planning/skills', ['category' => 'technical', 'skill_name' => 'PLC and SCADA'])->assertCreated();
});

it('asks for the certificate code of a skill that requires a certification', function (): void {
    $tenant = rpTenant();
    rpActAs($tenant, rpUser($tenant));

    $this->postJson('/api/v1/resource-planning/skills', ['category' => 'safety', 'skill_name' => 'Crane operator', 'requires_certification' => true])->assertUnprocessable();
    $this->postJson('/api/v1/resource-planning/skills', ['category' => 'safety', 'skill_name' => 'Crane operator', 'requires_certification' => true, 'certification_code' => 'crane_operator_gru'])->assertCreated();
});

it('records the proficiency of an employee and updates the same pair instead of duplicating it', function (): void {
    $tenant = rpTenant();
    rpActAs($tenant, rpUser($tenant));
    $employee = rpEmployee($tenant, 'Anna Rossi');
    $skill = rpSkill($tenant, 'PLC programming');

    $profile = $this->postJson('/api/v1/resource-planning/employee-skill-profiles', ['employee_id' => $employee, 'skill_id' => $skill, 'proficiency_level' => 3, 'valid_until_date' => '2027-12-31'])
        ->assertCreated()
        ->assertJsonPath('data.employee_name', 'Anna Rossi')
        ->assertJsonPath('data.skill_name', 'PLC programming')
        ->assertJsonPath('data.certification_status', 'not_required')
        ->json('data');

    $this->postJson('/api/v1/resource-planning/employee-skill-profiles', ['employee_id' => $employee, 'skill_id' => $skill, 'proficiency_level' => 5])
        ->assertCreated()
        ->assertJsonPath('data.id', $profile['id'])
        ->assertJsonPath('data.proficiency_level', 5)
        ->assertJsonPath('data.valid_until_date', null);

    $this->putJson("/api/v1/resource-planning/employee-skill-profiles/{$profile['id']}", ['proficiency_level' => 4, 'valid_until_date' => '2020-01-01'])
        ->assertOk()
        ->assertJsonPath('data.proficiency_level', 4)
        ->assertJsonPath('data.is_valid', false);

    expect($this->getJson("/api/v1/resource-planning/employee-skill-profiles?employee_id={$employee}")->assertOk()->json('data'))->toHaveCount(1);

    $this->deleteJson("/api/v1/resource-planning/employee-skill-profiles/{$profile['id']}")->assertNoContent();
    expect($this->getJson('/api/v1/resource-planning/employee-skill-profiles')->assertOk()->json('data'))->toBe([]);

    // A deleted profile of the pair is brought back by recording it again.
    $this->postJson('/api/v1/resource-planning/employee-skill-profiles', ['employee_id' => $employee, 'skill_id' => $skill, 'proficiency_level' => 2])->assertCreated();
    expect($this->getJson('/api/v1/resource-planning/employee-skill-profiles')->json('data'))->toHaveCount(1);
});

it('keeps the proficiency between 1 and 5 and refuses skills and employees of another tenant', function (): void {
    $tenant = rpTenant();
    rpActAs($tenant, rpUser($tenant));
    $employee = rpEmployee($tenant);
    $skill = rpSkill($tenant);
    $foreign = rpSkill(rpTenant('IT'), 'Foreign skill');

    foreach ([0, 6] as $level) {
        $this->postJson('/api/v1/resource-planning/employee-skill-profiles', ['employee_id' => $employee, 'skill_id' => $skill, 'proficiency_level' => $level])->assertUnprocessable();
    }

    $this->postJson('/api/v1/resource-planning/employee-skill-profiles', ['employee_id' => $employee, 'skill_id' => $foreign, 'proficiency_level' => 3])->assertUnprocessable();
});

it('shows whether the safety certificate a skill requires is valid (Mod 099)', function (): void {
    $tenant = rpTenant();
    rpActAs($tenant, rpUser($tenant));
    $skill = rpSkill($tenant, 'Forklift driving', 'safety', true, 'forklift_patentino_muletto');
    $valid = rpEmployee($tenant, 'Anna Valid');
    $expired = rpEmployee($tenant, 'Bruno Expired');
    $missing = rpEmployee($tenant, 'Carla Missing');
    $revoked = rpEmployee($tenant, 'Dario Revoked');

    foreach ([$valid, $expired, $missing, $revoked] as $employee) {
        rpGiveSkill($tenant, $employee, $skill, 4);
    }

    rpHseCertificate($tenant, $valid, 'forklift_patentino_muletto', '2099-01-01');
    rpHseCertificate($tenant, $expired, 'forklift_patentino_muletto', '2020-01-01');
    rpHseCertificate($tenant, $revoked, 'forklift_patentino_muletto', '2099-01-01', '2026-01-01 00:00:00');

    $status = collect($this->getJson('/api/v1/resource-planning/employee-skill-profiles')->assertOk()->json('data'))->pluck('certification_status', 'employee_name');

    expect($status['Anna Valid'])->toBe('valid')
        ->and($status['Bruno Expired'])->toBe('expired')
        ->and($status['Carla Missing'])->toBe('missing')
        ->and($status['Dario Revoked'])->toBe('missing');
});

it('lets the members read but only planners write', function (): void {
    $tenant = rpTenant();
    rpActAs($tenant, rpUser($tenant, 'member'));

    $this->getJson('/api/v1/resource-planning/skills')->assertOk();
    $this->postJson('/api/v1/resource-planning/skills', ['category' => 'technical', 'skill_name' => 'Welding'])->assertForbidden();

    rpActAs($tenant, rpUser($tenant, 'project_manager'));
    $this->postJson('/api/v1/resource-planning/skills', ['category' => 'technical', 'skill_name' => 'Welding'])->assertCreated();
});
