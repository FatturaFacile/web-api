<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Reader\XLSX\Reader;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

/**
 * A new company with its own VAT number, so several can exist in one test.
 */
function biTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name, 'legal_name' => "{$name} Srl", 'vat_number' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT), 'fiscal_code' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT), 'country' => 'IT', 'default_currency' => 'EUR']);
}

/**
 * A member of the company with the given role (password generated at runtime), not authenticated.
 */
function biUser(Tenant $tenant, string $role = 'admin'): User
{
    $password = Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9);
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => $password, 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

/**
 * Authenticates as a member of the company and sends its header on the next requests.
 */
function biActAs(Tenant $tenant, string $role = 'admin'): User
{
    $user = biUser($tenant, $role);
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);

    return $user;
}

function biCustomer(Tenant $tenant, string $name, string $country = 'IT'): Contact
{
    return Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => $name, 'country' => $country]);
}

/**
 * A posted sales invoice of a customer.
 *
 * @param  array<string, mixed>  $overrides
 */
function biInvoice(Tenant $tenant, Contact $customer, string $date, string $net, array $overrides = [], bool $posted = true): Invoice
{
    $invoice = (new Invoice)->forceFill([
        'contact_id' => $customer->id, 'invoice_type' => 'standard', 'invoice_number' => Str::random(8), 'date' => $date, 'due_date' => $date,
        'currency' => 'EUR', 'total_net' => $net, 'total_tax' => '0', 'total_gross' => $net, 'total_paid' => '0', 'payment_status' => 'unpaid', 'status' => $posted ? 'posted' : 'draft',
        ...$overrides,
    ]);
    $invoice->tenant_id = $tenant->id;

    if ($posted) {
        $invoice->posted_at = Carbon::parse($date);
    }

    $invoice->save();

    return $invoice;
}

/**
 * @param  list<string>  $dimensions
 * @param  list<string>  $measures
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function biSpec(array $dimensions, array $measures, array $extra = []): array
{
    return ['dimensions' => $dimensions, 'measures' => $measures, ...$extra];
}

/**
 * Reads an xlsx workbook with the OpenSpout reader: sheet name => rows (list of cell values, typed as stored).
 *
 * @return array<string, list<list<mixed>>>
 */
function biReadXlsx(string $binary): array
{
    $path = tempnam(sys_get_temp_dir(), 'bi');
    file_put_contents($path, $binary);
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(static fn ($cell): mixed => $cell->getValue(), $row->cells);
        }

        $sheets[$sheet->getName()] = $rows;
    }

    $reader->close();
    unlink($path);

    return $sheets;
}
