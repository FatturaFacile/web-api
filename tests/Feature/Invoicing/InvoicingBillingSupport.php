<?php

declare(strict_types=1);

use App\Enums\DeliveryNoteStatus;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesDeliveryNoteItem;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;

/**
 * Two Italian companies with an open fiscal calendar for this year and the next, a customer each, and an accountant
 * (invoicing writer) signed in on the first one.
 */
function billingSetUp(object $test): void
{
    $test->tenant = Tenant::create(['name' => 'Fatture Alfa', 'legal_name' => 'Fatture Alfa Srl', 'country' => 'IT', 'default_currency' => 'EUR', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580',
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI']]);
    $test->otherTenant = Tenant::create(['name' => 'Fatture Beta', 'legal_name' => 'Fatture Beta Srl', 'country' => 'IT', 'default_currency' => 'EUR']);

    foreach ([$test->tenant, $test->otherTenant] as $tenant) {
        fiscalCalendar($tenant, (int) now()->year);
        fiscalCalendar($tenant, (int) now()->year + 1);
    }

    $test->customer = billingInTenant($test->tenant, fn (): Contact => Contact::create(['tenant_id' => $test->tenant->id, 'type' => 'client', 'company_name' => 'Bianchi SpA', 'country' => 'IT', 'vat_number' => '01234567897', 'sdi_code' => 'ABC1234']));
    billingInTenant($test->tenant, fn () => $test->customer->addresses()->create(['tenant_id' => $test->tenant->id, 'type' => 'billing', 'street' => 'Via Dante 2', 'postal_code' => '00100', 'city' => 'Roma', 'country' => 'IT']));
    $test->secondCustomer = billingInTenant($test->tenant, fn (): Contact => Contact::create(['tenant_id' => $test->tenant->id, 'type' => 'client', 'company_name' => 'Verdi Srl', 'country' => 'IT', 'vat_number' => '07643520567']));
    $test->foreignCustomer = billingInTenant($test->otherTenant, fn (): Contact => Contact::create(['tenant_id' => $test->otherTenant->id, 'type' => 'client', 'company_name' => 'Neri Srl', 'country' => 'IT']));

    $test->accountant = billingActor($test, 'accountant');
}

/**
 * @template TReturn
 *
 * @param  callable(): TReturn  $callback
 * @return TReturn
 */
function billingInTenant(Tenant $tenant, callable $callback): mixed
{
    return app(TenantContext::class)->runWithTenant($tenant, $callback);
}

function billingActor(object $test, string $role, ?Tenant $tenant = null): User
{
    $tenant ??= $test->tenant;
    $user = User::create(['name' => ucfirst($role), 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);
    billingActAs($test, $user, $tenant);

    return $user;
}

function billingActAs(object $test, User $user, ?Tenant $tenant = null): void
{
    Sanctum::actingAs($user);
    $test->withHeader('X-Tenant-Id', ($tenant ?? $test->tenant)->id);
    app(TenantContext::class)->setTenant($tenant ?? $test->tenant);
}

/**
 * A confirmed sales order: 10 x 100.00 at 22% and 5 x 40.00 at 10% (net 1200.00).
 *
 * @param  list<array{0: string, 1: string, 2: string}>|null  $lines  quantity, unit price, VAT rate
 */
function billingOrder(Tenant $tenant, Contact $customer, ?array $lines = null, string $currency = 'EUR'): SalesOrder
{
    return billingInTenant($tenant, function () use ($tenant, $customer, $lines, $currency): SalesOrder {
        $lines ??= [['10', '100.00', '22'], ['5', '40.00', '10']];
        $order = SalesOrder::query()->create([
            'tenant_id' => $tenant->id, 'contact_id' => $customer->id, 'order_number' => 'SO-'.Str::upper(Str::random(6)),
            'date' => now()->toDateString(), 'currency' => $currency, 'status' => 'confirmed', 'billing_status' => 'unbilled',
        ]);
        $net = '0';

        foreach ($lines as $index => [$quantity, $price, $rate]) {
            $lineTotal = bcmul($quantity, $price, 2);
            $net = bcadd($net, $lineTotal, 2);
            SalesOrderItem::query()->create([
                'tenant_id' => $tenant->id, 'sales_order_id' => $order->id, 'description' => 'Articolo '.($index + 1),
                'quantity' => $quantity, 'unit_price' => $price, 'tax_rate' => $rate, 'discount_percentage' => '0', 'line_total' => $lineTotal,
            ]);
        }

        $order->update(['total_net' => $net]);

        return $order->load('items');
    });
}

/**
 * A dispatched delivery note of an order for the given quantities of its lines.
 *
 * @param  array<int, string>  $quantities  quantity per order line index
 */
function billingDeliveryNote(Tenant $tenant, SalesOrder $order, array $quantities, ?string $date = null): SalesDeliveryNote
{
    return billingInTenant($tenant, function () use ($tenant, $order, $quantities, $date): SalesDeliveryNote {
        $note = SalesDeliveryNote::query()->create([
            'tenant_id' => $tenant->id, 'contact_id' => $order->contact_id, 'sales_order_id' => $order->id,
            'ddt_number' => 'DDT-'.Str::upper(Str::random(6)), 'date' => $date ?? now()->toDateString(),
            'status' => DeliveryNoteStatus::Issued->value, 'shipping_status' => 'dispatched',
        ]);

        foreach ($quantities as $index => $quantity) {
            $item = $order->items->values()[$index];
            SalesDeliveryNoteItem::query()->create([
                'tenant_id' => $tenant->id, 'delivery_note_id' => $note->id, 'sales_order_item_id' => $item->id,
                'description' => $item->description, 'quantity' => $quantity, 'unit_price' => $item->unit_price, 'tax_rate' => $item->tax_rate,
            ]);
        }

        return $note->load('items');
    });
}

/**
 * Debit and credit totals of the posted entries of a document, which must be equal.
 *
 * @return array{0: string, 1: string}
 */
function billingEntryTotals(string $documentId): array
{
    $entryIds = JournalEntry::withoutGlobalScopes()->where('source_document_id', $documentId)->pluck('id');
    expect($entryIds)->not->toBeEmpty();
    $lines = JournalLine::withoutGlobalScopes()->whereIn('journal_entry_id', $entryIds)->get();

    return [
        $lines->reduce(static fn (string $sum, JournalLine $line): string => bcadd($sum, (string) $line->debit_amount, 4), '0'),
        $lines->reduce(static fn (string $sum, JournalLine $line): string => bcadd($sum, (string) $line->credit_amount, 4), '0'),
    ];
}

function billingAssertBalanced(string $documentId): void
{
    [$debit, $credit] = billingEntryTotals($documentId);
    expect(bccomp($debit, $credit, 4))->toBe(0)->and(bccomp($debit, '0', 4))->toBe(1);
}
