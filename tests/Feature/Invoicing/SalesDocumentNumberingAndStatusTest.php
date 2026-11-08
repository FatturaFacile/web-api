<?php

declare(strict_types=1);

use App\Enums\DeliveryNoteStatus;
use App\Enums\QuoteStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Models\InvoiceCreditApplication;
use Modules\Invoicing\Models\SalesInvoice;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create(['name' => 'Numerazione Srl', 'legal_name' => 'Numerazione Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);

    $user = User::create(['name' => 'Titolare', 'email' => Str::random(10).'@example.it', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    Sanctum::actingAs($user);

    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Cliente SpA', 'country' => 'IT']);
    $this->lines = [['description' => 'Pallet', 'quantity' => 4, 'unit_price' => 100, 'tax_rate' => 22]];
});

function numberingApi(): mixed
{
    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

/**
 * @return array<string, mixed>
 */
function moduleHeader(array $extra = []): array
{
    return ['tenant_id' => (string) test()->tenant->id, 'contact_id' => (string) test()->contact->id, ...$extra];
}

test('quotes and orders share one gapless series between the sales API and the invoicing module', function (): void {
    $appQuote = numberingApi()->postJson('/api/v1/sales/quotes', ['contact_id' => $this->contact->id, 'date' => '2026-03-10', 'currency' => 'EUR', 'items' => $this->lines])->assertCreated();
    $moduleQuote = app(SalesQuoteServiceInterface::class)->createQuote(moduleHeader(['quote_number' => 'CLIENT-CHOSEN']), $this->lines);
    $moduleRouteQuote = numberingApi()->postJson('/api/v1/invoicing/quotes', ['contact_id' => $this->contact->id, 'quote_number' => 'IGNORED', 'items' => $this->lines])->assertCreated();

    expect($appQuote->json('data.quote_number'))->toBe('QT-2026-0001')
        ->and($moduleQuote->quote_number)->toBe('QT-2026-0002')
        ->and($moduleRouteQuote->json('quote_number'))->toBe('QT-2026-0003');

    $appOrder = numberingApi()->postJson('/api/v1/sales/orders', ['contact_id' => $this->contact->id, 'date' => '2026-03-10', 'currency' => 'EUR', 'status' => 'confirmed', 'items' => $this->lines])->assertCreated();
    $moduleOrder = app(SalesOrderServiceInterface::class)->createOrder(moduleHeader(['order_number' => 'CLIENT-CHOSEN']), $this->lines);
    $converted = app(SalesQuoteServiceInterface::class)->convertToOrder($moduleQuote, 'tester');

    expect($appOrder->json('data.order_number'))->toBe('SO-2026-0001')
        ->and($moduleOrder->order_number)->toBe('SO-2026-0002')
        ->and($converted->order_number)->toBe('SO-2026-0003')
        ->and($moduleQuote->fresh()->status)->toBe(QuoteStatus::Accepted->value);
});

test('delivery notes are progressive across the sales API route and the invoicing module, and the client cannot pick the number', function (): void {
    $appOrder = numberingApi()->postJson('/api/v1/sales/orders', ['contact_id' => $this->contact->id, 'date' => '2026-03-10', 'currency' => 'EUR', 'status' => 'confirmed', 'items' => $this->lines])->assertCreated();
    $appLine = $appOrder->json('data.items.0.id');
    $moduleOrder = app(SalesOrderServiceInterface::class)->submitOrder(app(SalesOrderServiceInterface::class)->createOrder(moduleHeader(), $this->lines), 'tester');
    $moduleLine = (string) $moduleOrder->items->first()->id;

    $first = numberingApi()->postJson("/api/v1/sales/orders/{$appOrder->json('data.id')}/delivery-notes", ['lines' => [$appLine => 1]])->assertCreated();
    $second = app(DeliveryFulfillmentServiceInterface::class)->createDeliveryNote($moduleOrder, [], [['sales_order_item_id' => $moduleLine, 'quantity' => 1.0]]);
    $third = numberingApi()->postJson('/api/v1/invoicing/delivery-notes', [
        'sales_order_id' => $moduleOrder->id,
        'ddt_number' => 'DDT-CLIENT-1',
        'items' => [['sales_order_item_id' => $moduleLine, 'quantity' => 1]],
    ])->assertCreated();
    $fourth = numberingApi()->postJson("/api/v1/sales/orders/{$appOrder->json('data.id')}/delivery-notes", ['lines' => [$appLine => 1]])->assertCreated();

    expect([$first->json('data.ddt_number'), $second->ddt_number, $third->json('ddt_number'), $fourth->json('data.ddt_number')])
        ->toBe(['DDT-2026-0001', 'DDT-2026-0002', 'DDT-2026-0003', 'DDT-2026-0004'])
        ->and($second->date->toDateString())->toBe('2026-03-15')
        ->and(DB::table('sales_orders')->where('id', $appOrder->json('data.id'))->value('status'))->toBe(SalesOrderStatus::InFulfillment->value)
        ->and($moduleOrder->fresh()->status)->toBe(SalesOrderStatus::InFulfillment->value);
});

test('the year of the series is the year of the document date', function (): void {
    $order = app(SalesOrderServiceInterface::class)->createOrder(moduleHeader(['date' => '2025-12-30']), $this->lines);
    app(SalesOrderServiceInterface::class)->submitOrder($order, 'tester');
    $note = app(DeliveryFulfillmentServiceInterface::class)->createDeliveryNote($order, ['date' => '2025-12-31'], [['sales_order_item_id' => (string) $order->items->first()->id, 'quantity' => 1.0]]);
    $current = app(SalesOrderServiceInterface::class)->createOrder(moduleHeader(), $this->lines);

    expect($order->order_number)->toBe('SO-2025-0001')
        ->and($note->ddt_number)->toBe('DDT-2025-0001')
        ->and($current->order_number)->toBe('SO-2026-0001');
});

test('a failed insert gives its number back, so the series stays without gaps', function (): void {
    expect(fn () => app(SalesQuoteServiceInterface::class)->createQuote(moduleHeader(['contact_id' => (string) Str::uuid7()]), $this->lines))
        ->toThrow(QueryException::class);

    $quote = app(SalesQuoteServiceInterface::class)->createQuote(moduleHeader(), $this->lines);

    expect($quote->quote_number)->toBe('QT-2026-0001');
});

test('an order on credit hold or already shipped cannot receive a delivery note, and a shipped order cannot be cancelled', function (): void {
    $order = app(SalesOrderServiceInterface::class)->createOrder(moduleHeader(), $this->lines);
    $line = (string) $order->items->first()->id;
    $order->update(['status' => SalesOrderStatus::OnHold->value]);

    expect(fn () => app(DeliveryFulfillmentServiceInterface::class)->createDeliveryNote($order, [], [['sales_order_item_id' => $line, 'quantity' => 1.0]]))
        ->toThrow(ValidationException::class);

    $order->update(['status' => SalesOrderStatus::Confirmed->value]);
    $note = app(DeliveryFulfillmentServiceInterface::class)->createDeliveryNote($order, [], [['sales_order_item_id' => $line, 'quantity' => 4.0]]);

    expect($note->ddt_number)->toBe('DDT-2026-0001')
        ->and($order->fresh()->status)->toBe(SalesOrderStatus::Shipped->value)
        ->and(fn () => app(SalesOrderServiceInterface::class)->cancelOrder($order->fresh(), 'too late', 'tester'))->toThrow(ValidationException::class);
});

test('a delivery note is dispatched once', function (): void {
    $order = app(SalesOrderServiceInterface::class)->submitOrder(app(SalesOrderServiceInterface::class)->createOrder(moduleHeader(), $this->lines), 'tester');
    $note = app(DeliveryFulfillmentServiceInterface::class)->createDeliveryNote($order, [], [['sales_order_item_id' => (string) $order->items->first()->id, 'quantity' => 1.0]]);

    $dispatched = app(DeliveryFulfillmentServiceInterface::class)->dispatchDelivery($note, 'warehouse');

    expect($dispatched->status)->toBe(DeliveryNoteStatus::Issued->value)
        ->and(fn () => app(DeliveryFulfillmentServiceInterface::class)->dispatchDelivery($dispatched->fresh(), 'warehouse'))->toThrow(ValidationException::class);
});

test('the sales API refuses a status change the state machine does not allow', function (): void {
    $quoteId = numberingApi()->postJson('/api/v1/sales/quotes', ['contact_id' => $this->contact->id, 'date' => '2026-03-10', 'currency' => 'EUR', 'status' => 'rejected', 'items' => $this->lines])->assertCreated()->json('data.id');

    numberingApi()->putJson("/api/v1/sales/quotes/{$quoteId}", ['status' => 'sent'])
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    numberingApi()->postJson('/api/v1/sales/quotes', ['contact_id' => $this->contact->id, 'date' => '2026-03-10', 'currency' => 'EUR', 'status' => 'pending_approval', 'items' => $this->lines])
        ->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('the status vocabularies are one per document and every state machine stays inside its enum', function (): void {
    foreach ([QuoteStatus::class, SalesOrderStatus::class, DeliveryNoteStatus::class] as $statuses) {
        foreach ($statuses::cases() as $status) {
            expect($status->canTransitionTo($status))->toBeTrue();

            foreach ($status->transitions() as $target) {
                expect($target)->toBeInstanceOf($statuses);
            }
        }
    }

    expect(array_diff(QuoteStatus::manualValues(), QuoteStatus::values()))->toBe([])
        ->and(array_diff(SalesOrderStatus::manualValues(), SalesOrderStatus::values()))->toBe([])
        ->and(QuoteStatus::allows('pending_approval', QuoteStatus::Accepted))->toBeFalse()
        ->and(QuoteStatus::allows('converted', QuoteStatus::Accepted))->toBeFalse()
        ->and(QuoteStatus::Rejected->isFinal())->toBeTrue()
        ->and(DeliveryNoteStatus::allows('invoiced', DeliveryNoteStatus::Issued))->toBeFalse();
});

test('the data migration remaps the legacy quote status once and is harmless when run again', function (): void {
    $quote = app(SalesQuoteServiceInterface::class)->createQuote(moduleHeader(), $this->lines);
    DB::table('quotes')->where('id', $quote->id)->update(['status' => 'converted']);

    $migration = require database_path('migrations/2027_04_11_000100_remap_legacy_sales_document_statuses.php');
    $migration->up();
    $migration->up();

    expect(DB::table('quotes')->where('id', $quote->id)->value('status'))->toBe(QuoteStatus::LegacyValues['converted'])
        ->and(DB::table('quotes')->whereNotIn('status', QuoteStatus::values())->count())->toBe(0);
});

test('a credit note application is read and summed through Eloquent and cannot be deleted', function (): void {
    $invoice = SalesInvoice::factory()->posted()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id]);
    $creditNote = SalesInvoice::factory()->creditNote()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id]);

    $application = InvoiceCreditApplication::create([
        'tenant_id' => $this->tenant->id,
        'credit_note_id' => $creditNote->id,
        'original_invoice_id' => $invoice->id,
        'applied_amount' => '120.50',
        'application_date' => '2026-03-15',
    ]);

    expect(InvoiceCreditApplication::query()->whereKey($application->id)->exists())->toBeTrue()
        ->and((string) $creditNote->creditApplications()->sum('applied_amount'))->toBe('120.50')
        ->and($invoice->appliedCredits()->count())->toBe(1)
        ->and($application->fresh()->getCasts())->not->toHaveKey('deleted_at')
        ->and(fn () => $application->delete())->toThrow(LogicException::class);

    expect(InvoiceCreditApplication::query()->count())->toBe(1);
});
