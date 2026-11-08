<?php

declare(strict_types=1);

/**
 * Worker of the delivery note numbering concurrency test. Each mode boots the application on its own connection:
 *
 *   php delivery_note_numbering_worker.php setup <workers>          creates one order per worker, prints the fixture as JSON
 *   php delivery_note_numbering_worker.php deliver <fixture> <index> <startAt>
 *                                                                    waits for the agreed instant, creates one delivery note
 *                                                                    (even index: invoicing module, odd: sales API action)
 *                                                                    and prints its number
 *   php delivery_note_numbering_worker.php cleanup <fixture>
 *
 * The audit ledger is unbound: its rows cannot be deleted afterwards and its tenant lock would serialize the workers,
 * hiding what the test is there to prove.
 */

use App\Actions\Sales\ConvertOrderToDeliveryNote;
use App\Models\Contact;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Services\AuditLedgerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Models\SalesOrder as ModuleSalesOrder;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
unset($app[AuditLedgerService::class]);

$context = $app->make(TenantContext::class);
$mode = $argv[1];

if ($mode === 'setup') {
    $tenant = Tenant::create(['name' => 'DDT '.bin2hex(random_bytes(4)), 'legal_name' => 'Spedizioni Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $context->setTenant($tenant);
    $contact = Contact::create(['tenant_id' => $tenant->id, 'company_name' => 'Cliente SpA', 'country' => 'IT']);

    $orders = [];

    for ($index = 0; $index < (int) $argv[2]; $index++) {
        if ($index % 2 === 0) {
            $moduleOrder = $app->make(SalesOrderServiceInterface::class)->createOrder(
                ['tenant_id' => $tenant->id, 'contact_id' => $contact->id],
                [['description' => 'Pallet', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 22]]
            );
            // Only a confirmed order can be shipped.
            $orders[] = (string) $app->make(SalesOrderServiceInterface::class)->submitOrder($moduleOrder, 'setup')->id;

            continue;
        }

        $order = SalesOrder::create(['tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'order_number' => 'SETUP-'.$index, 'date' => now()->toDateString(), 'currency' => 'EUR', 'status' => 'confirmed']);
        $order->items()->make(['description' => 'Pallet', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 22, 'line_total' => 200])->forceFill(['tenant_id' => $tenant->id])->save();
        $orders[] = (string) $order->id;
    }

    echo json_encode(['tenant' => $tenant->id, 'orders' => $orders]);

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

$index = (int) $argv[3];
$orderId = $fixture['orders'][$index];

while (microtime(true) < (float) $argv[4]) {
    usleep(1000);
}

if ($index % 2 === 0) {
    $order = ModuleSalesOrder::query()->with('items')->findOrFail($orderId);
    $note = $app->make(DeliveryFulfillmentServiceInterface::class)->createDeliveryNote($order, [], [
        ['sales_order_item_id' => (string) $order->items->first()->id, 'quantity' => 1.0],
    ]);
} else {
    $note = $app->make(ConvertOrderToDeliveryNote::class)->execute(SalesOrder::query()->findOrFail($orderId));
}

echo (string) $note->ddt_number;
