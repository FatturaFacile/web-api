<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Projects\Models\ProjectTemplate;

require_once __DIR__.'/ProjectsSupport.php';

uses(RefreshDatabase::class);

beforeEach(fn () => projectsSetUp($this));
afterEach(fn () => app(TenantContext::class)->clear());

test('a project WBS is saved as a template with relative dates, dependencies and roles, and reopens as a project', function (): void {
    $project = projectsProject($this, ['planned_start_date' => '2027-01-04', 'planned_end_date' => '2027-06-30']);

    $phase = $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['title' => 'Build', 'node_type' => 'phase'])->assertCreated()->json('data.id');
    $first = $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['title' => 'Backend', 'node_type' => 'work_package', 'parent_node_id' => $phase, 'start_date' => '2027-01-04', 'end_date' => '2027-01-08', 'planned_hours' => 40, 'required_role' => 'developer'])->assertCreated()->json('data.id');
    $second = $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['title' => 'Frontend', 'node_type' => 'work_package', 'parent_node_id' => $phase, 'start_date' => '2027-01-11', 'end_date' => '2027-01-15', 'planned_hours' => 40])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/projects/{$project->id}/dependencies", ['predecessor_node_id' => $first, 'successor_node_id' => $second, 'dependency_type' => 'FS', 'lag_days' => 0])->assertCreated();

    $template = $this->postJson('/api/v1/projects/templates', ['code' => 'BUILD', 'name' => 'Build template', 'source_project_id' => $project->id])
        ->assertCreated()->assertJsonPath('data.node_count', 3)->assertJsonPath('data.dependency_count', 1)->json('data');

    $structure = ProjectTemplate::query()->findOrFail($template['id'])->default_wbs_structure;
    $leaves = $structure['nodes'][0]['children'];
    expect($leaves[0]['start_offset_days'])->toBe(0)
        ->and($leaves[0]['duration_days'])->toBe(5)
        ->and($leaves[0]['required_role'])->toBe('developer')
        ->and($leaves[1]['start_offset_days'])->toBe(7);

    $this->getJson('/api/v1/projects/templates')->assertOk()->assertJsonPath('data.0.code', 'BUILD');
});

test('a template can be created from a structure, is validated and can be deleted', function (): void {
    $this->postJson('/api/v1/projects/templates', ['code' => 'X', 'name' => 'Broken'])->assertUnprocessable();

    $this->postJson('/api/v1/projects/templates', [
        'code' => 'BAD', 'name' => 'Bad dependency',
        'structure' => ['nodes' => [['ref' => 'a', 'title' => 'A']], 'dependencies' => [['predecessor' => 'a', 'successor' => 'zzz', 'type' => 'FS']]],
    ])->assertUnprocessable();

    $created = $this->postJson('/api/v1/projects/templates', [
        'code' => 'OK', 'name' => 'Fine',
        'structure' => ['nodes' => [['ref' => 'a', 'title' => 'A', 'duration_days' => 2], ['ref' => 'b', 'title' => 'B']], 'dependencies' => [['predecessor' => 'a', 'successor' => 'b', 'type' => 'FS']]],
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/projects/templates', ['code' => 'OK', 'name' => 'Duplicate code', 'structure' => ['nodes' => [['title' => 'A']]]])->assertUnprocessable();

    $this->deleteJson("/api/v1/projects/templates/{$created['id']}")->assertNoContent();
    $this->getJson('/api/v1/projects/templates')->assertOk()->assertJsonCount(0, 'data');
});

test('templates are managed by project managers only and stay inside the tenant', function (): void {
    projectsActAs($this, $this->member);
    $this->postJson('/api/v1/projects/templates', ['code' => 'M', 'name' => 'Member', 'structure' => ['nodes' => [['title' => 'A']]]])->assertForbidden();

    projectsActAs($this, $this->manager);
    $this->postJson('/api/v1/projects/templates', ['code' => 'M', 'name' => 'Manager', 'structure' => ['nodes' => [['title' => 'A']]]])->assertCreated();

    $stranger = projectsUser($this, 'owner', $this->otherTenant);
    projectsActAs($this, $stranger, $this->otherTenant);
    $this->getJson('/api/v1/projects/templates')->assertOk()->assertJsonCount(0, 'data');
});
