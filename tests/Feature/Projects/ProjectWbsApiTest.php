<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Projects\Events\ProjectBaselineCapturedEvent;
use Modules\Projects\Events\ProjectScheduleRecalculatedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTemplate;
use Modules\Projects\Models\ProjectWbsDependency;
use Modules\Projects\Models\ProjectWbsNode;

require_once __DIR__.'/ProjectsSupport.php';

uses(RefreshDatabase::class);

beforeEach(fn () => projectsSetUp($this));
afterEach(fn () => app(TenantContext::class)->clear());

/**
 * An active project that starts on Monday 2027-01-04.
 *
 * @param  array<string, mixed>  $attributes
 */
function wbsProject(object $test, array $attributes = []): Project
{
    return projectsProject($test, ['planned_start_date' => '2027-01-04', 'planned_end_date' => '2027-06-30', ...$attributes]);
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function wbsNode(object $test, Project $project, array $payload): array
{
    return $test->postJson("/api/v1/projects/{$project->id}/wbs-nodes", $payload)->assertCreated()->json('data');
}

/**
 * A work package with dates.
 *
 * @return array<string, mixed>
 */
function wbsTask(object $test, Project $project, string $title, string $start, string $end, array $extra = []): array
{
    return wbsNode($test, $project, ['title' => $title, 'node_type' => 'work_package', 'start_date' => $start, 'end_date' => $end, ...$extra]);
}

/**
 * @return array<string, mixed>
 */
function wbsGantt(object $test, Project $project, string $query = ''): array
{
    return $test->getJson("/api/v1/projects/{$project->id}/gantt{$query}")->assertOk()->json('data');
}

/**
 * @return array<string, string> WBS code by node title
 */
function wbsCodes(object $test, Project $project): array
{
    return collect(wbsGantt($test, $project)['nodes'])->pluck('wbs_code', 'title')->all();
}

function wbsLink(object $test, Project $project, string $predecessor, string $successor, string $type = 'FS', int $lag = 0): TestResponse
{
    return $test->postJson("/api/v1/projects/{$project->id}/dependencies", ['predecessor_node_id' => $predecessor, 'successor_node_id' => $successor, 'dependency_type' => $type, 'lag_days' => $lag]);
}

test('nested nodes get dotted WBS codes and the roll-up replaces the values typed on a node with children', function (): void {
    $project = wbsProject($this);

    $phase = wbsNode($this, $project, ['title' => 'Phase', 'node_type' => 'Phase', 'planned_hours' => 999]);
    $a = wbsTask($this, $project, 'A', '2027-01-04', '2027-01-08', ['parent_node_id' => $phase['id'], 'planned_hours' => 10, 'planned_cost' => 100, 'planned_revenue' => 150, 'percent_complete' => 50]);
    $b = wbsTask($this, $project, 'B', '2027-01-11', '2027-01-15', ['parent_node_id' => $phase['id'], 'planned_hours' => 30, 'planned_cost' => 300, 'planned_revenue' => 450, 'percent_complete' => 100]);

    expect($phase['wbs_code'])->toBe('1')->and($phase['node_type'])->toBe('phase')
        ->and($a['wbs_code'])->toBe('1.1')
        ->and($b['wbs_code'])->toBe('1.2');

    $rows = collect(wbsGantt($this, $project)['nodes'])->keyBy('title');

    expect($rows['Phase'])->toMatchArray([
        'planned_hours' => '40.00', 'planned_cost' => '400.00', 'planned_revenue' => '600.00', 'percent_complete' => '87.50',
        'start_date' => '2027-01-04', 'end_date' => '2027-01-15', 'is_summary' => true, 'depth' => 0,
    ])->and($rows['A'])->toMatchArray(['depth' => 1, 'is_summary' => false]);

    $project->refresh();

    expect((string) $project->planned_hours)->toBe('40.00')
        ->and((string) $project->planned_revenue)->toBe('600.00')
        ->and((string) $project->progress_percentage)->toBe('87.50');
});

test('a third level rolls up through the second without double counting', function (): void {
    $project = wbsProject($this);
    $phase = wbsNode($this, $project, ['title' => 'Phase', 'node_type' => 'phase']);
    $deliverable = wbsNode($this, $project, ['title' => 'Deliverable', 'node_type' => 'deliverable', 'parent_node_id' => $phase['id'], 'planned_hours' => 500]);
    $one = wbsTask($this, $project, 'One', '2027-01-04', '2027-01-08', ['parent_node_id' => $deliverable['id'], 'planned_hours' => 4, 'actual_hours' => 2, 'planned_cost' => 40]);
    $two = wbsTask($this, $project, 'Two', '2027-01-04', '2027-01-08', ['parent_node_id' => $deliverable['id'], 'planned_hours' => 6, 'planned_cost' => 60]);

    expect($one['wbs_code'])->toBe('1.1.1')->and($two['wbs_code'])->toBe('1.1.2');

    $rows = collect(wbsGantt($this, $project)['nodes'])->keyBy('title');

    expect($rows['Deliverable'])->toMatchArray(['planned_hours' => '10.00', 'actual_hours' => '2.00', 'planned_cost' => '100.00'])
        ->and($rows['Phase'])->toMatchArray(['planned_hours' => '10.00', 'actual_hours' => '2.00', 'planned_cost' => '100.00']);
});

test('inserting at a position and deleting a node renumber the siblings', function (): void {
    $project = wbsProject($this);
    $first = wbsNode($this, $project, ['title' => 'First']);
    wbsNode($this, $project, ['title' => 'Second']);
    wbsNode($this, $project, ['title' => 'Inserted', 'sort_order' => 1]);

    expect(wbsCodes($this, $project))->toBe(['Inserted' => '1', 'First' => '2', 'Second' => '3']);

    $this->deleteJson("/api/v1/projects/{$project->id}/wbs-nodes/{$first['id']}")->assertOk();

    expect(wbsCodes($this, $project))->toBe(['Inserted' => '1', 'Second' => '2']);
});

test('moving a node under another parent renumbers it and a node cannot move below its own descendant', function (): void {
    $project = wbsProject($this);
    $p1 = wbsNode($this, $project, ['title' => 'P1', 'node_type' => 'phase']);
    $p2 = wbsNode($this, $project, ['title' => 'P2', 'node_type' => 'phase']);
    $child = wbsNode($this, $project, ['title' => 'Child', 'parent_node_id' => $p1['id']]);

    expect($child['wbs_code'])->toBe('1.1');

    $this->putJson("/api/v1/projects/{$project->id}/wbs-nodes/{$child['id']}", ['parent_node_id' => $p2['id']])
        ->assertOk()->assertJsonPath('data.wbs_code', '2.1')->assertJsonPath('data.parent_node_id', $p2['id']);

    $this->putJson("/api/v1/projects/{$project->id}/wbs-nodes/{$p2['id']}", ['parent_node_id' => $child['id']])->assertUnprocessable()->assertJsonValidationErrors(['parent_node_id']);
    $this->putJson("/api/v1/projects/{$project->id}/wbs-nodes/{$p2['id']}", ['parent_node_id' => $p2['id']])->assertUnprocessable();

    expect(wbsCodes($this, $project))->toBe(['P1' => '1', 'P2' => '2', 'Child' => '2.1']);
});

test('the reorder endpoint moves several nodes and renumbers the whole tree', function (): void {
    $project = wbsProject($this);
    $p1 = wbsNode($this, $project, ['title' => 'P1', 'node_type' => 'phase']);
    $p2 = wbsNode($this, $project, ['title' => 'P2', 'node_type' => 'phase']);

    $response = $this->patchJson("/api/v1/projects/{$project->id}/wbs-nodes/reorder", ['nodes' => [['id' => $p2['id'], 'sort_order' => 1]]])->assertOk();

    expect($response->json('data.0.id'))->toBe($p2['id'])
        ->and(wbsCodes($this, $project))->toBe(['P2' => '1', 'P1' => '2']);

    $this->patchJson("/api/v1/projects/{$project->id}/wbs-nodes/reorder", ['nodes' => [['id' => $p1['id'], 'parent_node_id' => $p2['id']]]])->assertOk();

    expect(wbsCodes($this, $project))->toBe(['P2' => '1', 'P1' => '1.1']);

    $this->patchJson("/api/v1/projects/{$project->id}/wbs-nodes/reorder", ['nodes' => [['id' => $p2['id'], 'parent_node_id' => $p1['id']]]])->assertUnprocessable();
    $this->patchJson("/api/v1/projects/{$project->id}/wbs-nodes/reorder", ['nodes' => []])->assertUnprocessable();
});

test('a milestone lasts a single day and cannot have children', function (): void {
    $project = wbsProject($this);
    $milestone = wbsNode($this, $project, ['title' => 'Go live', 'node_type' => 'Milestone', 'start_date' => '2027-02-01', 'planned_hours' => 8]);

    expect($milestone)->toMatchArray(['node_type' => 'milestone', 'start_date' => '2027-02-01', 'end_date' => '2027-02-01', 'planned_hours' => '8.00']);

    $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['title' => 'Below', 'parent_node_id' => $milestone['id']])->assertUnprocessable()->assertJsonValidationErrors(['parent_node_id']);
});

test('a finish-to-start dependency pushes the successor after the predecessor and both become critical', function (): void {
    $project = wbsProject($this);
    $a = wbsTask($this, $project, 'A', '2027-01-04', '2027-01-08', ['planned_hours' => 40]);
    $b = wbsTask($this, $project, 'B', '2027-01-06', '2027-01-07', ['planned_hours' => 16]);

    wbsLink($this, $project, $a['id'], $b['id'])->assertCreated()
        ->assertJsonPath('data.dependency_type', 'FS')->assertJsonPath('data.lag_days', 0);

    $b = ProjectWbsNode::query()->findOrFail($b['id']);
    $a = ProjectWbsNode::query()->findOrFail($a['id']);

    expect($b->start_date->toDateString())->toBe('2027-01-09')
        ->and($b->end_date->toDateString())->toBe('2027-01-10')
        ->and($b->early_start->toDateString())->toBe('2027-01-09')
        ->and($b->early_finish->toDateString())->toBe('2027-01-10')
        ->and($b->total_float_days)->toBe(0)
        ->and($b->is_critical)->toBeTrue()
        ->and($a->is_critical)->toBeTrue()
        ->and($a->late_finish->toDateString())->toBe('2027-01-08');

    $gantt = wbsGantt($this, $project);

    expect($gantt['cpm']['project_finish'])->toBe('2027-01-10')
        ->and($gantt['cpm']['critical_path'])->toBe([$a->id, $b->id])
        ->and($gantt['dependencies'])->toHaveCount(1);
});

test('changing the lag of a dependency cascades onto the schedule and a lag never pulls a node earlier', function (): void {
    $project = wbsProject($this);
    $a = wbsTask($this, $project, 'A', '2027-01-04', '2027-01-08');
    $b = wbsTask($this, $project, 'B', '2027-01-06', '2027-01-07');
    $dependency = wbsLink($this, $project, $a['id'], $b['id'])->assertCreated()->json('data.id');

    $this->putJson("/api/v1/projects/{$project->id}/dependencies/{$dependency}", ['lag_days' => 2])->assertOk()->assertJsonPath('data.lag_days', 2);

    expect(ProjectWbsNode::query()->findOrFail($b['id'])->start_date->toDateString())->toBe('2027-01-11');

    $this->putJson("/api/v1/projects/{$project->id}/dependencies/{$dependency}", ['dependency_type' => 'ss', 'lag_days' => 0])->assertOk()->assertJsonPath('data.dependency_type', 'SS');

    // Start-to-start with no lag no longer constrains B, which keeps its (already pushed) planned start.
    expect(ProjectWbsNode::query()->findOrFail($b['id'])->start_date->toDateString())->toBe('2027-01-11');
});

test('start-to-start, finish-to-finish and negative lag are scheduled through the API', function (): void {
    $project = wbsProject($this);
    $a = wbsTask($this, $project, 'A', '2027-01-04', '2027-01-08'); // 5 days
    $ss = wbsTask($this, $project, 'SS', '2027-01-04', '2027-01-05'); // 2 days
    $ff = wbsTask($this, $project, 'FF', '2027-01-04', '2027-01-05'); // 2 days
    $lead = wbsTask($this, $project, 'Lead', '2027-01-04', '2027-01-06'); // 3 days

    wbsLink($this, $project, $a['id'], $ss['id'], 'SS', 3)->assertCreated();
    wbsLink($this, $project, $a['id'], $ff['id'], 'FF', 0)->assertCreated();
    wbsLink($this, $project, $a['id'], $lead['id'], 'FS', -2)->assertCreated();

    $nodes = ProjectWbsNode::query()->where('project_id', $project->id)->get()->keyBy('title');

    expect($nodes['SS']->start_date->toDateString())->toBe('2027-01-07')
        ->and($nodes['FF']->end_date->toDateString())->toBe('2027-01-08')
        ->and($nodes['FF']->start_date->toDateString())->toBe('2027-01-07')
        ->and($nodes['Lead']->start_date->toDateString())->toBe('2027-01-07')
        ->and($nodes['Lead']->end_date->toDateString())->toBe('2027-01-09');
});

test('floats appear on the nodes that are not on the critical path', function (): void {
    $project = wbsProject($this);
    $a = wbsTask($this, $project, 'A', '2027-01-04', '2027-01-08');
    $long = wbsTask($this, $project, 'Long', '2027-01-04', '2027-01-15'); // 12 days
    $short = wbsTask($this, $project, 'Short', '2027-01-04', '2027-01-06'); // 3 days
    $end = wbsNode($this, $project, ['title' => 'End', 'node_type' => 'milestone', 'start_date' => '2027-01-04']);

    foreach ([$long, $short] as $node) {
        wbsLink($this, $project, $a['id'], $node['id'])->assertCreated();
        wbsLink($this, $project, $node['id'], $end['id'])->assertCreated();
    }

    $nodes = ProjectWbsNode::query()->where('project_id', $project->id)->get()->keyBy('title');

    expect($nodes['Long']->is_critical)->toBeTrue()
        ->and($nodes['Short']->is_critical)->toBeFalse()
        ->and($nodes['Short']->total_float_days)->toBe(9)
        ->and($nodes['Short']->free_float_days)->toBe(9)
        ->and($nodes['End']->is_critical)->toBeTrue()
        ->and($nodes['End']->start_date->toDateString())->toBe('2027-01-21');
});

test('a dependency that would close a cycle, repeat a link or touch a summary node is refused', function (): void {
    $project = wbsProject($this);
    $a = wbsTask($this, $project, 'A', '2027-01-04', '2027-01-08');
    $b = wbsTask($this, $project, 'B', '2027-01-04', '2027-01-08');
    $c = wbsTask($this, $project, 'C', '2027-01-04', '2027-01-08');
    $phase = wbsNode($this, $project, ['title' => 'Phase', 'node_type' => 'phase']);
    wbsNode($this, $project, ['title' => 'Inside', 'parent_node_id' => $phase['id']]);

    wbsLink($this, $project, $a['id'], $b['id'])->assertCreated();
    wbsLink($this, $project, $b['id'], $c['id'])->assertCreated();

    wbsLink($this, $project, $c['id'], $a['id'])->assertUnprocessable()->assertJsonValidationErrors(['successor_node_id']);
    wbsLink($this, $project, $a['id'], $b['id'])->assertUnprocessable()->assertJsonValidationErrors(['successor_node_id']);
    wbsLink($this, $project, $a['id'], $a['id'])->assertUnprocessable();
    wbsLink($this, $project, $phase['id'], $a['id'])->assertUnprocessable()->assertJsonValidationErrors(['predecessor_node_id']);
    wbsLink($this, $project, $a['id'], $b['id'], 'XX')->assertUnprocessable()->assertJsonValidationErrors(['dependency_type']);

    expect(ProjectWbsDependency::query()->where('project_id', $project->id)->count())->toBe(2);
});

test('a node with dependencies cannot receive children because dependencies link leaf nodes only', function (): void {
    $project = wbsProject($this);
    $a = wbsTask($this, $project, 'A', '2027-01-04', '2027-01-08');
    $b = wbsTask($this, $project, 'B', '2027-01-04', '2027-01-08');
    wbsLink($this, $project, $a['id'], $b['id'])->assertCreated();

    $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['title' => 'Child', 'parent_node_id' => $a['id']])->assertStatus(409);
});

test('deleting a dependency or a node removes the links and recalculates', function (): void {
    $project = wbsProject($this);
    $a = wbsTask($this, $project, 'A', '2027-01-04', '2027-01-08');
    $b = wbsTask($this, $project, 'B', '2027-01-06', '2027-01-07');
    $dependency = wbsLink($this, $project, $a['id'], $b['id'])->assertCreated()->json('data.id');

    $this->deleteJson("/api/v1/projects/{$project->id}/dependencies/{$dependency}")->assertOk();
    expect(ProjectWbsDependency::query()->count())->toBe(0);

    wbsLink($this, $project, $a['id'], $b['id'])->assertCreated();
    $this->deleteJson("/api/v1/projects/{$project->id}/wbs-nodes/{$a['id']}")->assertOk();

    expect(ProjectWbsDependency::query()->count())->toBe(0)->and(ProjectWbsNode::query()->count())->toBe(1);
});

test('deleting a node removes its whole subtree but not when hours or costs are already recorded below it', function (): void {
    $project = wbsProject($this);
    $phase = wbsNode($this, $project, ['title' => 'Phase', 'node_type' => 'phase']);
    $child = wbsNode($this, $project, ['title' => 'Child', 'parent_node_id' => $phase['id']]);
    wbsNode($this, $project, ['title' => 'Grandchild', 'parent_node_id' => $child['id'], 'actual_hours' => 5]);

    $this->deleteJson("/api/v1/projects/{$project->id}/wbs-nodes/{$phase['id']}")->assertStatus(409);
    expect(ProjectWbsNode::query()->count())->toBe(3);

    $this->putJson("/api/v1/projects/{$project->id}/wbs-nodes/".ProjectWbsNode::query()->where('title', 'Grandchild')->value('id'), ['actual_hours' => 0])->assertOk();
    $this->deleteJson("/api/v1/projects/{$project->id}/wbs-nodes/{$phase['id']}")->assertOk();

    expect(ProjectWbsNode::query()->count())->toBe(0);
});

test('the recalculate endpoint runs the whole critical path and reports a cycle that bypassed the checks', function (): void {
    Event::fake([ProjectScheduleRecalculatedEvent::class]);

    $project = wbsProject($this);
    $a = wbsTask($this, $project, 'A', '2027-01-04', '2027-01-08');
    $b = wbsTask($this, $project, 'B', '2027-01-06', '2027-01-07');
    wbsLink($this, $project, $a['id'], $b['id'])->assertCreated();

    $this->postJson("/api/v1/projects/{$project->id}/cpm/recalculate")->assertOk()
        ->assertJsonPath('data.critical_path', [$a['id'], $b['id']])
        ->assertJsonPath('data.project_finish', '2027-01-10')
        ->assertJsonPath('data.shifted_nodes', 0)
        ->assertJsonPath('data.node_count', 2);

    Event::assertDispatched(ProjectScheduleRecalculatedEvent::class);

    ProjectWbsDependency::create(['tenant_id' => $project->tenant_id, 'company_id' => $project->company_id, 'project_id' => $project->id, 'predecessor_node_id' => $b['id'], 'successor_node_id' => $a['id'], 'dependency_type' => 'FS', 'lag_days' => 0]);

    $this->postJson("/api/v1/projects/{$project->id}/cpm/recalculate")->assertUnprocessable()->assertJsonValidationErrors(['dependencies']);
});

test('baselines are numbered and the variance shows schedule, hours and cost drift', function (): void {
    Event::fake([ProjectBaselineCapturedEvent::class]);

    $project = wbsProject($this);
    $a = wbsTask($this, $project, 'A', '2027-01-04', '2027-01-08', ['planned_hours' => 40, 'planned_cost' => 1000]);
    $b = wbsTask($this, $project, 'B', '2027-01-06', '2027-01-07', ['planned_hours' => 16, 'planned_cost' => 400]);
    wbsLink($this, $project, $a['id'], $b['id'])->assertCreated();

    $first = $this->postJson("/api/v1/projects/{$project->id}/baselines", ['name' => 'Kick-off'])->assertCreated()
        ->assertJsonPath('data.baseline_number', 1)->assertJsonPath('data.name', 'Kick-off')->assertJsonPath('data.node_count', 2)->json('data.id');
    $this->postJson("/api/v1/projects/{$project->id}/baselines")->assertCreated()->assertJsonPath('data.baseline_number', 2)->assertJsonPath('data.name', 'Baseline 2');

    Event::assertDispatched(ProjectBaselineCapturedEvent::class, 2);
    $this->getJson("/api/v1/projects/{$project->id}/baselines")->assertOk()->assertJsonCount(2, 'data');

    $this->putJson("/api/v1/projects/{$project->id}/wbs-nodes/{$a['id']}", ['end_date' => '2027-01-13', 'planned_hours' => 60])->assertOk();
    wbsTask($this, $project, 'Extra', '2027-01-18', '2027-01-19', ['planned_hours' => 8, 'planned_cost' => 100]);

    $variance = $this->getJson("/api/v1/projects/{$project->id}/baselines/{$first}/variance")->assertOk()->json('data');
    $rows = collect($variance['nodes'])->keyBy('title');

    expect($variance['baseline']['baseline_number'])->toBe(1)
        ->and($rows['A'])->toMatchArray(['change' => 'changed', 'finish_variance_days' => 5, 'start_variance_days' => 0, 'planned_hours_variance' => '20.00'])
        ->and($rows['B'])->toMatchArray(['change' => 'changed', 'start_variance_days' => 5, 'finish_variance_days' => 5])
        ->and($rows['Extra']['change'])->toBe('added')
        ->and($variance['summary'])->toMatchArray(['baseline_finish_date' => '2027-01-10', 'finish_date' => '2027-01-19', 'finish_variance_days' => 9, 'planned_hours_variance' => '28.00']);

    $gantt = wbsGantt($this, $project, "?baselineId={$first}");

    expect($gantt['baseline']['baseline']['baseline_number'])->toBe(1)
        ->and($gantt['baseline']['summary']['finish_variance_days'])->toBe(9)
        ->and($gantt['nodes'][0]['baseline']['change'])->toBe('changed')
        ->and($gantt)->toHaveKey('tasks');

    $this->getJson("/api/v1/projects/{$project->id}/baselines/".Str::uuid().'/variance')->assertNotFound();
    $this->getJson("/api/v1/projects/{$project->id}/gantt?baselineId=".Str::uuid())->assertNotFound();
});

test('a project without WBS nodes has no baseline to capture', function (): void {
    $project = wbsProject($this);

    $this->postJson("/api/v1/projects/{$project->id}/baselines")->assertUnprocessable()->assertJsonValidationErrors(['baseline']);
});

test('the kanban groups the work items by status and leaves summary nodes out', function (): void {
    $project = wbsProject($this);
    $phase = wbsNode($this, $project, ['title' => 'Phase', 'node_type' => 'phase']);
    wbsNode($this, $project, ['title' => 'Doing', 'parent_node_id' => $phase['id'], 'status' => 'in_progress']);
    wbsNode($this, $project, ['title' => 'Finished', 'parent_node_id' => $phase['id'], 'status' => 'done']);
    wbsNode($this, $project, ['title' => 'Waiting', 'parent_node_id' => $phase['id']]);

    $columns = collect($this->getJson("/api/v1/projects/{$project->id}/kanban")->assertOk()->json('data'))->keyBy('status');

    expect($columns->keys()->all())->toBe(['backlog', 'todo', 'in_progress', 'review', 'done', 'blocked'])
        ->and($columns['in_progress']['count'])->toBe(1)
        ->and($columns['in_progress']['items'][0]['title'])->toBe('Doing')
        ->and($columns['done']['items'][0]['percent_complete'])->toBe('100.00')
        ->and($columns['todo']['count'])->toBe(1)
        ->and($columns['todo']['items'][0]['wbs_code'])->toBe('1.3');
});

test('node validation refuses an end before the start, an unknown type and a parent from another project', function (): void {
    $project = wbsProject($this);
    $other = wbsProject($this);
    $foreign = wbsNode($this, $other, ['title' => 'Foreign']);

    $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['title' => 'X', 'start_date' => '2027-01-10', 'end_date' => '2027-01-05'])->assertUnprocessable()->assertJsonValidationErrors(['end_date']);
    $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['title' => 'X', 'node_type' => 'epic'])->assertUnprocessable()->assertJsonValidationErrors(['node_type']);
    $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['title' => 'X', 'planned_hours' => -1])->assertUnprocessable()->assertJsonValidationErrors(['planned_hours']);
    $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['node_type' => 'phase'])->assertUnprocessable()->assertJsonValidationErrors(['title']);
    $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['title' => 'X', 'parent_node_id' => $foreign['id']])->assertUnprocessable()->assertJsonValidationErrors(['parent_node_id']);
});

test('only managers write the WBS, readers read it and other companies see nothing', function (): void {
    $project = wbsProject($this);
    $node = wbsNode($this, $project, ['title' => 'A']);

    projectsActAs($this, $this->member);
    $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['title' => 'B'])->assertForbidden();
    $this->putJson("/api/v1/projects/{$project->id}/wbs-nodes/{$node['id']}", ['title' => 'B'])->assertForbidden();
    $this->deleteJson("/api/v1/projects/{$project->id}/wbs-nodes/{$node['id']}")->assertForbidden();
    $this->postJson("/api/v1/projects/{$project->id}/baselines")->assertForbidden();
    $this->postJson("/api/v1/projects/{$project->id}/cpm/recalculate")->assertForbidden();

    projectsActAs($this, $this->reader);
    $this->getJson("/api/v1/projects/{$project->id}/gantt")->assertOk();
    $this->getJson("/api/v1/projects/{$project->id}/kanban")->assertOk();

    projectsActAs($this, $this->manager);
    $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['title' => 'By the manager'])->assertCreated();

    $stranger = projectsUser($this, 'owner', $this->otherTenant);
    projectsActAs($this, $stranger, $this->otherTenant);
    $this->getJson("/api/v1/projects/{$project->id}/kanban")->assertNotFound();
    $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['title' => 'Intruder'])->assertNotFound();
    $this->putJson("/api/v1/projects/{$project->id}/wbs-nodes/{$node['id']}", ['title' => 'Intruder'])->assertNotFound();
    $this->postJson("/api/v1/projects/{$project->id}/baselines")->assertNotFound();
});

test('a closed project no longer accepts WBS changes', function (): void {
    $project = wbsProject($this, ['status' => 'closed']);

    $this->postJson("/api/v1/projects/{$project->id}/wbs-nodes", ['title' => 'Late'])->assertStatus(409);
});

test('the status endpoint moves a project through planning, activity, hold, completion and cancellation', function (): void {
    $draft = wbsProject($this, ['status' => 'draft']);

    $this->patchJson("/api/v1/projects/{$draft->id}/status", ['status' => 'Planning'])->assertOk()->assertJsonPath('data.status', 'planned');
    $this->patchJson("/api/v1/projects/{$draft->id}/status", ['status' => 'Active'])->assertOk()->assertJsonPath('data.status', 'active');
    $this->patchJson("/api/v1/projects/{$draft->id}/status", ['status' => 'OnHold', 'reason' => 'Waiting for the customer'])->assertOk()->assertJsonPath('data.status', 'on_hold');
    $this->patchJson("/api/v1/projects/{$draft->id}/status", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
    $this->patchJson("/api/v1/projects/{$draft->id}/status", ['status' => 'Completed'])->assertOk()->assertJsonPath('data.status', 'completed');
    $this->patchJson("/api/v1/projects/{$draft->id}/status", ['status' => 'cancelled'])->assertStatus(409);

    $running = wbsProject($this);
    $this->patchJson("/api/v1/projects/{$running->id}/status", ['status' => 'Cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->patchJson("/api/v1/projects/{$running->id}/status", ['status' => 'closed'])->assertUnprocessable()->assertJsonValidationErrors(['status']);

    projectsActAs($this, $this->member);
    $this->patchJson("/api/v1/projects/{$draft->id}/status", ['status' => 'active'])->assertForbidden();
});

test('the portfolio lists the projects with filters and KPIs over the whole filtered set', function (): void {
    $overdue = projectsProject($this, ['project_number' => 'PRJ-LATE', 'health_status' => 'red', 'planned_hours' => '100', 'actual_hours' => '30', 'planned_end_date' => now()->subDay()->toDateString(), 'cup_code' => 'J81B19000780001', 'cig_code' => '7654321ABC']);
    projectsProject($this, ['project_number' => 'PRJ-OK', 'health_status' => 'green', 'planned_hours' => '50']);
    projectsProject($this, ['project_number' => 'PRJ-DONE', 'status' => 'completed', 'planned_end_date' => now()->subDays(2)->toDateString()]);

    $all = $this->getJson('/api/v1/projects/portfolio')->assertOk();

    expect($all->json('meta.total'))->toBe(3)
        ->and($all->json('meta.kpis'))->toMatchArray([
            'project_count' => 3, 'overdue_count' => 1, 'at_risk_count' => 1,
            'by_status' => ['active' => 2, 'completed' => 1], 'planned_hours' => '150.00', 'actual_hours' => '30.00', 'contract_value' => '300000.00',
        ])
        ->and($all->json('data.0'))->toHaveKeys(['id', 'project_number', 'status', 'customer_name', 'cup_code', 'planned_hours', 'progress_percentage']);

    $active = $this->getJson('/api/v1/projects/portfolio?status=active&per_page=1')->assertOk();

    expect($active->json('meta.total'))->toBe(2)->and($active->json('data'))->toHaveCount(1)->and($active->json('meta.kpis.project_count'))->toBe(2);

    $this->getJson('/api/v1/projects/portfolio?has_public_codes=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $overdue->id)->assertJsonPath('data.0.customer_name', 'Cliente Spa');
    $this->getJson('/api/v1/projects/portfolio?search=7654321ABC')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/projects/portfolio?health_status=red')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/projects/portfolio?status=active,completed')->assertOk()->assertJsonPath('meta.total', 3);

    $stranger = projectsUser($this, 'owner', $this->otherTenant);
    projectsActAs($this, $stranger, $this->otherTenant);
    $this->getJson('/api/v1/projects/portfolio')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('meta.kpis.project_count', 0);
});

/**
 * A software delivery template: a phase with two work packages and a milestone, linked finish-to-start.
 *
 * @param  array<string, mixed>  $overrides
 */
function wbsTemplate(object $test, array $overrides = []): ProjectTemplate
{
    return ProjectTemplate::create([
        'tenant_id' => $test->tenant->id,
        'company_id' => $test->tenant->id,
        'code' => 'SW-'.Str::upper(Str::random(5)),
        'name' => 'Software delivery',
        'default_wbs_structure' => [
            'nodes' => [[
                'title' => 'Analysis', 'node_type' => 'phase', 'children' => [
                    ['ref' => 'req', 'title' => 'Requirements', 'node_type' => 'work_package', 'start_offset_days' => 0, 'duration_days' => 5, 'planned_hours' => 40, 'planned_cost' => 2000, 'required_role' => 'analyst'],
                    ['ref' => 'design', 'title' => 'Design', 'node_type' => 'work_package', 'start_offset_days' => 0, 'duration_days' => 3, 'planned_hours' => 24, 'planned_cost' => 1500, 'required_role' => 'architect'],
                    ['ref' => 'signoff', 'title' => 'Sign-off', 'node_type' => 'milestone', 'start_offset_days' => 8],
                ],
            ]],
            'dependencies' => [
                ['predecessor' => 'req', 'successor' => 'design', 'type' => 'FS', 'lag_days' => 0],
                ['predecessor' => 'design', 'successor' => 'signoff', 'type' => 'FS', 'lag_days' => 0],
            ],
        ],
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wbsFromTemplatePayload(object $test, ProjectTemplate $template, array $overrides = []): array
{
    return [
        'template_id' => $template->id,
        'contact_id' => $test->contact->id,
        'project_number' => 'PRJ-TPL-'.Str::upper(Str::random(4)),
        'project_type' => 'consulting',
        'billing_method' => 'fixed_price',
        'planned_start_date' => '2027-02-01',
        'project_manager_user_id' => $test->manager->id,
        'contract_value' => 20000,
        ...$overrides,
    ];
}

test('a project is created from a template with its WBS, shifted dates, dependencies, roles and public codes', function (): void {
    $template = wbsTemplate($this);

    $response = $this->postJson('/api/v1/projects/from-template', wbsFromTemplatePayload($this, $template, ['cup_code' => 'J81B19000780001', 'cig_code' => '7654321ABC']))->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.name', 'Software delivery')
        ->assertJsonPath('data.planned_start_date', '2027-02-01')
        ->assertJsonPath('data.planned_end_date', '2027-02-09')
        ->assertJsonPath('data.cup_code', 'J81B19000780001')
        ->assertJsonPath('data.planned_hours', '64.00');
    $project = Project::query()->findOrFail($response->json('data.id'));

    $nodes = ProjectWbsNode::query()->where('project_id', $project->id)->get()->keyBy('title');

    expect($nodes)->toHaveCount(4)
        ->and($nodes['Analysis']->wbs_code)->toBe('1')
        ->and($nodes['Requirements']->wbs_code)->toBe('1.1')
        ->and($nodes['Sign-off']->wbs_code)->toBe('1.3')
        ->and($nodes['Requirements']->start_date->toDateString())->toBe('2027-02-01')
        ->and($nodes['Requirements']->end_date->toDateString())->toBe('2027-02-05')
        ->and($nodes['Design']->start_date->toDateString())->toBe('2027-02-06')
        ->and($nodes['Design']->end_date->toDateString())->toBe('2027-02-08')
        ->and($nodes['Design']->required_role)->toBe('architect')
        ->and($nodes['Sign-off']->start_date->toDateString())->toBe('2027-02-09')
        ->and($nodes['Sign-off']->end_date->toDateString())->toBe('2027-02-09')
        ->and($nodes['Design']->is_critical)->toBeTrue()
        ->and($nodes['Analysis']->start_date->toDateString())->toBe('2027-02-01')
        ->and($nodes['Analysis']->end_date->toDateString())->toBe('2027-02-09')
        ->and((string) $nodes['Analysis']->planned_cost)->toBe('3500.00')
        ->and(ProjectWbsDependency::query()->where('project_id', $project->id)->count())->toBe(2);
});

test('creating from a template honours an explicit end date and refuses a cyclic or malformed template', function (): void {
    $template = wbsTemplate($this);

    $this->postJson('/api/v1/projects/from-template', wbsFromTemplatePayload($this, $template, ['planned_end_date' => '2027-03-31', 'name' => 'Custom name']))->assertCreated()
        ->assertJsonPath('data.planned_end_date', '2027-03-31')->assertJsonPath('data.name', 'Custom name');

    $cyclic = wbsTemplate($this, ['default_wbs_structure' => [
        'nodes' => [['ref' => 'a', 'title' => 'A', 'duration_days' => 1], ['ref' => 'b', 'title' => 'B', 'duration_days' => 1]],
        'dependencies' => [['predecessor' => 'a', 'successor' => 'b'], ['predecessor' => 'b', 'successor' => 'a']],
    ]]);
    $this->postJson('/api/v1/projects/from-template', wbsFromTemplatePayload($this, $cyclic, ['project_number' => 'PRJ-CYCLE']))->assertUnprocessable();

    $dangling = wbsTemplate($this, ['default_wbs_structure' => [
        'nodes' => [['ref' => 'a', 'title' => 'A']],
        'dependencies' => [['predecessor' => 'a', 'successor' => 'missing']],
    ]]);
    $this->postJson('/api/v1/projects/from-template', wbsFromTemplatePayload($this, $dangling, ['project_number' => 'PRJ-DANGLING']))->assertUnprocessable();

    expect(Project::query()->whereIn('project_number', ['PRJ-CYCLE', 'PRJ-DANGLING'])->exists())->toBeFalse();
});

test('creating from a template validates the request and the permissions', function (): void {
    $template = wbsTemplate($this);
    $inactive = wbsTemplate($this, ['is_active' => false]);
    $number = 'PRJ-DUP';
    wbsProject($this, ['project_number' => $number]);

    $this->postJson('/api/v1/projects/from-template', wbsFromTemplatePayload($this, $template, ['project_number' => $number]))->assertUnprocessable()->assertJsonValidationErrors(['project_number']);
    $this->postJson('/api/v1/projects/from-template', wbsFromTemplatePayload($this, $template, ['template_id' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors(['template_id']);
    $this->postJson('/api/v1/projects/from-template', wbsFromTemplatePayload($this, $template, ['cup_code' => 'short']))->assertUnprocessable()->assertJsonValidationErrors(['cup_code']);
    $this->postJson('/api/v1/projects/from-template', wbsFromTemplatePayload($this, $inactive))->assertNotFound();

    projectsActAs($this, $this->member);
    $this->postJson('/api/v1/projects/from-template', wbsFromTemplatePayload($this, $template))->assertForbidden();
});
