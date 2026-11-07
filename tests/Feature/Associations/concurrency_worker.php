<?php

declare(strict_types=1);

/*
 * One process of the concurrency test. The test starts several of these at once against committed rows, because a lock
 * or a constraint can only be proved between separate database sessions. Usage:
 *
 *   php concurrency_worker.php setup <workers>
 *   php concurrency_worker.php <enrol|book|enter> <fixture file> <index> <start time>
 *   php concurrency_worker.php cleanup <fixture file>
 *
 * The audit ledger is unbound here: its rows cannot be deleted afterwards and its tenant lock would serialize the
 * workers, hiding what the test is there to prove.
 */

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Services\AuditLedgerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\AccessArea;
use Modules\Associations\Models\AccessDevice;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\BookableResource;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Services\AccessDeviceService;
use Modules\Associations\Services\AccessVerifier;
use Modules\Associations\Services\BookingService;
use Modules\Associations\Services\CourseService;
use Modules\Associations\Services\CredentialService;
use Modules\Associations\Services\MembershipService;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
unset($app[AuditLedgerService::class]);

$context = $app->make(TenantContext::class);
$mode = $argv[1];

if ($mode === 'setup') {
    $tenant = Tenant::create(['name' => 'Concurrency '.bin2hex(random_bytes(4)), 'legal_name' => 'Concurrency', 'country' => 'IT']);
    $context->setTenant($tenant);

    $year = AssociativeYear::factory()->create();
    $type = MembershipType::factory()->create();
    $memberships = $app->make(MembershipService::class);
    $credentials = $app->make(CredentialService::class);
    $members = [];
    $codes = [];

    for ($index = 0; $index < (int) $argv[2]; $index++) {
        $member = Member::factory()->create();
        $memberships->activate($memberships->request($member, $year, $type));
        $members[] = $member->id;
        $codes[] = $credentials->qrPayload($credentials->currentQr($member));
    }

    $area = AccessArea::factory()->create(['capacity' => 2, 'check_fee' => false]);

    echo json_encode([
        'tenant' => $tenant->id,
        'members' => $members,
        'codes' => $codes,
        'course' => Course::factory()->create(['capacity' => 2, 'waitlist_enabled' => false])->id,
        'resource' => BookableResource::factory()->create()->id,
        'area' => $area->id,
        'device' => $app->make(AccessDeviceService::class)->register(['area_id' => $area->id, 'name' => 'Gate'])['device']->id,
    ]);

    exit(0);
}

$fixture = json_decode((string) file_get_contents($argv[2]), true);
$tenant = Tenant::query()->findOrFail($fixture['tenant']);
$context->setTenant($tenant);

if ($mode === 'cleanup') {
    DB::statement("SET session_replication_role = 'replica'");

    $tables = DB::table('information_schema.columns')->where('column_name', 'tenant_id')->where('table_schema', 'public')->where('table_name', 'like', 'assoc_%')->pluck('table_name');

    foreach ($tables as $table) {
        DB::table($table)->where('tenant_id', $tenant->id)->delete();
    }

    DB::table('contacts')->where('tenant_id', $tenant->id)->delete();
    DB::table('document_sequences')->where('tenant_id', $tenant->id)->delete();
    DB::table('tenants')->where('id', $tenant->id)->delete();

    exit(0);
}

$index = (int) $argv[3];
$member = Member::query()->findOrFail($fixture['members'][$index]);

// Every worker has booted by now: they all leave at the same moment.
if ((float) $argv[4] > microtime(true)) {
    time_sleep_until((float) $argv[4]);
}

try {
    $outcome = match ($mode) {
        'enrol' => $app->make(CourseService::class)->enrol(Course::query()->findOrFail($fixture['course']), $member)->status->value,
        'book' => $app->make(BookingService::class)->book(
            BookableResource::query()->findOrFail($fixture['resource']),
            CarbonImmutable::parse('2030-01-01 10:00:00')->addMinutes($index * 5),
            CarbonImmutable::parse('2030-01-01 11:00:00')->addMinutes($index * 5),
        )->status,
        'enter' => $app->make(AccessVerifier::class)->verify(AccessDevice::query()->findOrFail($fixture['device']), $fixture['codes'][$index])['reason_code'],
    };
} catch (AssociationException $exception) {
    $outcome = 'refused';
}

echo $outcome;
