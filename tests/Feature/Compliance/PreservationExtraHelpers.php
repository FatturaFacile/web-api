<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\DocumentTrust\Models\PreservationItem;

require_once __DIR__.'/ComplianceHelpers.php';

const PE_BASE = '/api/v1/document-trust';

/**
 * An invoice of the tenant with a stored XML file.
 */
function peInvoice(Tenant $tenant, string $number = 'FT-1', string $date = '2027-01-15'): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente Rossi', 'vat_number' => '01234567890']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => $number, 'date' => $date, 'due_date' => '2027-02-15', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();
    InvoiceSdiMetadata::create(['invoice_id' => $invoice->id, 'transmission_format' => 'FPR12', 'file_name' => "IT00811720580_{$number}.xml", 'xml_content' => "<xml>{$number}</xml>"]);

    return $invoice;
}

function peOpenPackage(string $class = 'issued_invoice'): string
{
    return test()->postJson(PE_BASE.'/preservation-packages', ['document_class' => $class, 'name' => 'Pacchetto'])->assertCreated()->json('data.id');
}

/**
 * Closes a package holding one invoice (the package id, and its stored file).
 *
 * @return array{package: string, item: PreservationItem}
 */
function peClosedPackage(Tenant $tenant, string $number = 'FT-1'): array
{
    $package = peOpenPackage();
    test()->postJson(PE_BASE."/preservation-packages/{$package}/items", ['source_id' => peInvoice($tenant, $number)->id])->assertCreated();
    test()->postJson(PE_BASE."/preservation-packages/{$package}/close")->assertOk();

    return ['package' => $package, 'item' => PreservationItem::where('package_id', $package)->firstOrFail()];
}

/**
 * Switches the request to another (new) member of the tenant.
 */
function peActAs(Tenant $tenant, string $role = 'admin'): User
{
    app(TenantContext::class)->clear();

    return actingAsMember($tenant, $role);
}

/**
 * Runs a callback with a trigger of a table disabled (the way a tampering with raw SQL would have to).
 */
function peWithoutTrigger(string $table, string $trigger, Closure $callback): void
{
    DB::unprepared("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
    $callback();
    DB::unprepared("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
}
