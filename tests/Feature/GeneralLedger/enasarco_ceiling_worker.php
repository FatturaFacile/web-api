<?php

declare(strict_types=1);

/**
 * Worker of the Enasarco ceiling concurrency test. Each mode boots the application on its own connection:
 *
 *   php enasarco_ceiling_worker.php setup <drafts>           creates the fixture and prints it as JSON
 *   php enasarco_ceiling_worker.php issue <fixture> <index>  waits for the agreed instant, issues one draft, prints its base
 *   php enasarco_ceiling_worker.php cleanup <fixture>
 *
 * The audit ledger is unbound here: its rows cannot be deleted afterwards and its tenant lock would serialize the
 * workers, hiding what the test is there to prove.
 */

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Services\AuditLedgerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\EnasarcoParameter;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
unset($app[AuditLedgerService::class]);

$context = $app->make(TenantContext::class);
$mode = $argv[1];

if ($mode === 'setup') {
    $tenant = Tenant::create([
        'name' => 'Enasarco '.bin2hex(random_bytes(4)), 'legal_name' => 'Agente Srl', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR',
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'],
    ]);
    $context->setTenant($tenant);

    $principal = Contact::create(['tenant_id' => $tenant->id, 'company_name' => 'Mandante SpA', 'country' => 'IT', 'vat_number' => '01234567897']);
    $year = FiscalYear::create(['tenant_id' => $tenant->id, 'year_code' => 'FY2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => 'open']);
    FiscalPeriod::create(['tenant_id' => $tenant->id, 'fiscal_year_id' => $year->id, 'period_number' => 1, 'period_name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => 'open']);
    EnasarcoParameter::create(['tenant_id' => $tenant->id, 'year' => 2027, 'agent_type' => 'multi_mandate', 'rate_percent' => 10, 'agent_share_percent' => 50, 'annual_ceiling' => 1500, 'source' => 'Test figures']);

    $drafts = [];

    for ($index = 0; $index < (int) $argv[2]; $index++) {
        $drafts[] = (string) $app->make(InvoicingServiceInterface::class)->createInvoice([
            'tenant_id' => $tenant->id, 'contact_id' => $principal->id, 'date' => '2027-03-01', 'currency' => 'EUR', 'jurisdiction' => 'IT',
            'enasarco_enabled' => true, 'enasarco_agent_type' => 'multi_mandate', 'created_by' => null,
        ], [['description' => 'Provvigioni', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 22]])->id;
    }

    echo json_encode(['tenant' => $tenant->id, 'drafts' => $drafts]);

    exit(0);
}

$fixture = json_decode((string) file_get_contents($argv[2]), true);
$tenant = Tenant::query()->findOrFail($fixture['tenant']);
$context->setTenant($tenant);

if ($mode === 'cleanup') {
    DB::statement("SET session_replication_role = 'replica'");

    $tables = DB::table('information_schema.columns')->where('column_name', 'tenant_id')->where('table_schema', 'public')->where('table_name', '!=', 'tenants')->pluck('table_name')->unique();

    foreach ($tables as $table) {
        DB::table($table)->where('tenant_id', $tenant->id)->delete();
    }

    DB::table('tenants')->where('id', $tenant->id)->delete();

    exit(0);
}

$draft = SalesInvoice::query()->findOrFail($fixture['drafts'][(int) $argv[3]]);

while (microtime(true) < (float) $argv[4]) {
    usleep(1000);
}

$issued = $app->make(InvoicingServiceInterface::class)->postInvoice($draft, '');

echo (string) $issued->enasarco_base;
