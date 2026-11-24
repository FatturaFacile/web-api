<?php

declare(strict_types=1);

/*
 * One process of the helpdesk concurrency test. The test starts several of these at once against committed rows,
 * because a row lock can only be proved between separate database sessions. Usage:
 *
 *   php helpdesk_concurrency_worker.php setup <workers>
 *   php helpdesk_concurrency_worker.php <assign|merge> <fixture file> <index> <start time>
 *   php helpdesk_concurrency_worker.php cleanup <fixture file>
 *
 * The audit ledger is unbound here: its rows cannot be deleted afterwards and its tenant lock would serialize the
 * workers, hiding what the test is there to prove.
 */

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Services\AuditLedgerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Exceptions\HelpdeskRuleViolation;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskTicketService;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
unset($app[AuditLedgerService::class]);

$context = $app->make(TenantContext::class);
$mode = $argv[1];

if ($mode === 'setup') {
    $tenant = Tenant::create(['name' => 'Helpdesk concurrency '.bin2hex(random_bytes(4)), 'legal_name' => 'Concurrency', 'country' => 'IT']);
    $context->setTenant($tenant);

    $team = ServiceTeam::factory()->create(['tenant_id' => $tenant->id, 'assignment_strategy' => 'round_robin']);
    $agents = [];

    foreach ([1, 2] as $ignored) {
        $agents[] = ServiceTechnician::factory()->create(['tenant_id' => $tenant->id, 'team_id' => $team->id])->id;
    }

    $tickets = [];

    for ($index = 0; $index < (int) $argv[2]; $index++) {
        $tickets[] = Ticket::factory()->create(['tenant_id' => $tenant->id, 'status' => 'new', 'assigned_team_id' => $team->id])->id;
    }

    $source = Ticket::factory()->create(['tenant_id' => $tenant->id, 'status' => 'open'])->id;

    echo json_encode(['tenant' => $tenant->id, 'team' => $team->id, 'agents' => $agents, 'tickets' => $tickets, 'source' => $source], JSON_THROW_ON_ERROR);

    exit(0);
}

$fixture = json_decode((string) file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
$tenant = Tenant::query()->findOrFail($fixture['tenant']);
$context->setTenant($tenant);

if ($mode === 'cleanup') {
    DB::statement("SET session_replication_role = 'replica'");

    $tables = DB::table('information_schema.columns')->where('column_name', 'tenant_id')->where('table_schema', 'public')->where('table_name', 'like', 'esm_%')->pluck('table_name');

    foreach ($tables as $table) {
        DB::table($table)->where('tenant_id', $tenant->id)->delete();
    }

    DB::table('transactional_outbox')->where('tenant_id', $tenant->id)->delete();
    DB::table('tenants')->where('id', $tenant->id)->delete();
    DB::statement("SET session_replication_role = 'origin'");

    exit(0);
}

$index = (int) $argv[3];
time_sleep_until((float) $argv[4]);
$service = $app->make(HelpdeskTicketService::class);

try {
    if ($mode === 'assign') {
        $ticket = Ticket::query()->findOrFail($fixture['tickets'][$index]);
        echo $service->autoAssign($ticket, $fixture['team'], 'round_robin')->assigned_technician_id;
    } elseif ($mode === 'merge') {
        // Every worker merges the same source into a target of its own: only the first may succeed.
        $target = Ticket::query()->findOrFail($fixture['tickets'][$index]);
        $target->forceFill(['status' => 'open'])->save();
        $service->merge(Ticket::query()->findOrFail($fixture['source']), $target);
        echo 'merged';
    }
} catch (HelpdeskRuleViolation) {
    echo 'refused';
}
