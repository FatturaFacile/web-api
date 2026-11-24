<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Projects\Jobs\ComputeProjectEvmSnapshotsJob;
use Modules\Projects\Models\ProjectFinancialSnapshot;

require_once __DIR__.'/ProjectsSupport.php';

uses(RefreshDatabase::class);

beforeEach(fn () => projectsSetUp($this));
afterEach(fn () => app(TenantContext::class)->clear());

test('the nightly job snapshots running projects only and tags the snapshot as scheduled', function (): void {
    $running = projectsProject($this, ['status' => 'active']);
    $closed = projectsProject($this, ['status' => 'closed']);

    app()->call([new ComputeProjectEvmSnapshotsJob, 'handle']);

    $snapshot = ProjectFinancialSnapshot::query()->where('project_id', $running->id)->whereNull('wbs_node_id')->firstOrFail();

    expect($snapshot->snapshot_trigger)->toBe('scheduled')
        ->and(ProjectFinancialSnapshot::query()->where('project_id', $closed->id)->exists())->toBeFalse();
});
