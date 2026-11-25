<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Modules\Budgeting\Integration\ProcurementCommitmentSync;
use Modules\Budgeting\Integration\ProcurementSpendControl;
use Modules\Budgeting\Models\BudgetCommitment;
use Modules\Budgeting\Models\BudgetSpendCheck;
use Modules\Budgeting\Services\AvailabilityService;
use Modules\Procurement\Contracts\SpendControlInterface;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Procurement\Events\PurchaseOrderCancelledEvent;
use Modules\Procurement\Events\PurchaseRequisitionApprovedEvent;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Services\ProcurementApprovalService;

require_once __DIR__.'/Support/ControlHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = ctlTenant();
    $this->owner = ctlMember($this->tenant, 'owner');
    ctlAct($this->owner, $this->tenant);
    $this->expense = ctlAccount($this->tenant);
    $this->center = ctlCostCenter($this->tenant);
    // 12,000 for the year on the cost centre.
    $this->budget = ctlApprovedBudget($this->tenant, $this->expense, $this->center, '1000.00');
    $this->sync = app(ProcurementCommitmentSync::class);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @param  array<string, mixed>  $attributes
 */
function pcOrder(array $attributes = []): PurchaseOrder
{
    $net = (string) ($attributes['total_net'] ?? '4000.00');
    unset($attributes['total_net']);

    return PurchaseOrder::factory()->create([
        'tenant_id' => test()->tenant->id,
        'cost_center_id' => test()->center->id,
        'status' => 'approved',
        'date' => '2028-03-10',
        'currency' => 'EUR',
        'exchange_rate' => 1,
        'total_net' => $net,
        'net_amount' => $net,
        'total_tax' => bcmul($net, '0.22', 2),
        'tax_amount' => bcmul($net, '0.22', 2),
        'total_amount' => bcmul($net, '1.22', 2),
        'total_gross' => bcmul($net, '1.22', 2),
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function pcRequisition(array $attributes = []): PurchaseRequisition
{
    return PurchaseRequisition::factory()->create([
        'tenant_id' => test()->tenant->id,
        'cost_center_id' => test()->center->id,
        'status' => 'approved',
        'required_by_date' => '2028-03-20',
        'total_estimated_amount' => '5000.00',
        'currency' => 'EUR',
        'requestor_user_id' => test()->owner->id,
        ...$attributes,
    ]);
}

function pcOpen(): string
{
    return app(AvailabilityService::class)->forScope((string) test()->tenant->id, Carbon::parse('2028-06-01'), null, (string) test()->center->id, null)->committed;
}

test('the budgeting module takes over the spend lock and listens to purchasing', function (): void {
    expect(app(SpendControlInterface::class))->toBeInstanceOf(ProcurementSpendControl::class);

    foreach ([PurchaseRequisitionApprovedEvent::class, PurchaseOrderApprovedEvent::class, PurchaseOrderCancelledEvent::class, GoodsReceivedEvent::class] as $event) {
        expect(Event::hasListeners($event))->toBeTrue();
    }
});

test('an approved order commits its net amount, not the gross one, and keeps the period of its date', function (): void {
    $order = pcOrder(['total_net' => '4000.00']);

    $this->sync->syncOrder($order);

    $commitment = BudgetCommitment::query()->sole();
    expect($commitment->source_type)->toBe('purchase_order')->and($commitment->source_id)->toBe($order->id)->and($commitment->amount)->toBe('4000.00')
        ->and($commitment->status)->toBe('open')->and($commitment->fiscal_year)->toBe(2028)->and($commitment->period)->toBe(3)
        ->and($commitment->cost_center_id)->toBe($this->center->id)->and($commitment->openAmount())->toBe('4000.00')->and(pcOpen())->toBe('4000.00');

    // Syncing again does not duplicate it.
    $this->sync->syncOrder($order);
    expect(BudgetCommitment::query()->count())->toBe(1);
});

test('a draft or rejected order does not commit the budget', function (): void {
    $this->sync->syncOrder(pcOrder(['status' => 'draft']));
    $this->sync->syncOrder(pcOrder(['status' => 'rejected']));
    $this->sync->syncOrder(pcOrder(['status' => 'pending_approval']));

    expect(BudgetCommitment::query()->count())->toBe(0);
});

test('an order in another currency is committed at its exchange rate in the currency of the budget', function (): void {
    $order = pcOrder(['currency' => 'USD', 'exchange_rate' => '0.900000', 'total_net' => '1000.00']);

    $this->sync->syncOrder($order);

    expect(BudgetCommitment::query()->sole()->amount)->toBe('900.00');
});

test('receiving or invoicing part of an order consumes the commitment and the rest stays open', function (): void {
    $order = pcOrder(['status' => 'partially_received']);
    $line = PurchaseOrderLine::factory()->create(['tenant_id' => $this->tenant->id, 'purchase_order_id' => $order->id, 'line_number' => 1, 'quantity_ordered' => 10, 'quantity_received' => 5, 'unit_price' => 400, 'line_net_amount' => '4000.00']);

    $this->sync->syncOrder($order);
    $commitment = BudgetCommitment::query()->sole();
    expect($commitment->consumed_amount)->toBe('2000.00')->and($commitment->openAmount())->toBe('2000.00')->and(pcOpen())->toBe('2000.00');

    // The larger of received and invoiced counts: the ledger already holds the invoiced part.
    $line->update(['quantity_invoiced' => 8]);
    $this->sync->syncOrder($order);
    expect($commitment->refresh()->openAmount())->toBe('800.00');

    $line->update(['quantity_received' => 10, 'quantity_invoiced' => 10]);
    $order->update(['status' => 'received']);
    $this->sync->syncOrder($order);
    expect($commitment->refresh()->status)->toBe('closed')->and($commitment->openAmount())->toBe('0.00')->and(pcOpen())->toBe('0.00');
});

test('a cancelled quantity releases its part of the commitment and a closed order releases all of it', function (): void {
    $order = pcOrder(['status' => 'confirmed']);
    PurchaseOrderLine::factory()->create(['tenant_id' => $this->tenant->id, 'purchase_order_id' => $order->id, 'line_number' => 1, 'quantity_ordered' => 10, 'quantity_cancelled' => 4, 'unit_price' => 400, 'line_net_amount' => '4000.00']);

    $this->sync->syncOrder($order);
    expect(BudgetCommitment::query()->sole()->openAmount())->toBe('2400.00');

    $order->update(['status' => 'closed']);
    $this->sync->syncOrder($order);
    expect(BudgetCommitment::query()->sole()->openAmount())->toBe('0.00');
});

test('a cancelled order releases its commitment for good', function (): void {
    $order = pcOrder();
    $this->sync->syncOrder($order);
    $order->update(['status' => 'cancelled']);

    $this->sync->onOrderCancelled(new PurchaseOrderCancelledEvent(purchaseOrderId: $order->id, poNumber: (string) $order->po_number, reason: 'Supplier closed', tenantId: (string) $this->tenant->id, companyId: (string) $this->tenant->id, cancelledAmount: 4880.0));

    $commitment = BudgetCommitment::query()->sole();
    expect($commitment->status)->toBe('released')->and($commitment->release_reason)->toBe('cancelled')->and(pcOpen())->toBe('0.00');

    // A late event for the same order cannot bring it back.
    $order->update(['status' => 'approved']);
    $this->sync->syncOrder($order);
    expect(BudgetCommitment::query()->sole()->status)->toBe('released');
});

test('an order counts instead of its requisition and the requisition counts again when the order is cancelled', function (): void {
    $requisition = pcRequisition(['total_estimated_amount' => '5000.00']);
    $this->sync->syncRequisition($requisition);
    expect(pcOpen())->toBe('5000.00');

    $order = pcOrder(['total_net' => '3000.00', 'purchase_requisition_id' => $requisition->id]);
    $this->sync->syncOrder($order);

    $requisitionCommitment = BudgetCommitment::query()->where('source_type', 'requisition')->sole();
    expect($requisitionCommitment->released_amount)->toBe('3000.00')->and($requisitionCommitment->openAmount())->toBe('2000.00')->and(pcOpen())->toBe('5000.00');

    app(ProcurementSpendControl::class)->release($order);
    expect($requisitionCommitment->refresh()->released_amount)->toBe('0.00')->and(pcOpen())->toBe('5000.00');
});

test('an order for the whole requisition closes the requisition commitment', function (): void {
    $requisition = pcRequisition(['total_estimated_amount' => '5000.00']);
    $this->sync->syncRequisition($requisition);
    $requisition->update(['status' => 'converted_to_po']);
    $this->sync->syncOrder(pcOrder(['total_net' => '5000.00', 'purchase_requisition_id' => $requisition->id]));

    expect(BudgetCommitment::query()->where('source_type', 'requisition')->sole()->status)->toBe('closed')->and(pcOpen())->toBe('5000.00');
});

test('a cancelled or rejected requisition stops committing', function (): void {
    $requisition = pcRequisition();
    $this->sync->syncRequisition($requisition);
    expect(pcOpen())->toBe('5000.00');

    app(ProcurementSpendControl::class)->release($requisition);
    expect(BudgetCommitment::query()->sole()->status)->toBe('released')->and(pcOpen())->toBe('0.00');

    $draft = pcRequisition(['status' => 'draft']);
    $this->sync->syncRequisition($draft);
    expect(BudgetCommitment::query()->count())->toBe(1);
});

test('the lock blocks a requisition over the budget with the figures of the override request', function (): void {
    ctlRule($this->tenant, 'hard_block');
    $requisition = pcRequisition(['total_estimated_amount' => '13000.00']);
    $control = app(ProcurementSpendControl::class);

    try {
        $control->check($requisition, 'submit');
        $this->fail('The requisition should have been blocked.');
    } catch (ProcurementRuleViolation $exception) {
        expect($exception->field)->toBe('budget')->and($exception->getMessage())->toContain('12000.00')->and($exception->getMessage())->toContain('13000.00');
    }

    expect(BudgetSpendCheck::query()->sole()->outcome)->toBe('blocked');
    $control->check(pcRequisition(['total_estimated_amount' => '12000.00']), 'submit');
});

test('a warning lets the order through and is logged', function (): void {
    ctlRule($this->tenant, 'warning');

    app(ProcurementSpendControl::class)->check(pcOrder(['status' => 'draft', 'total_net' => '15000.00']), 'create');

    $check = BudgetSpendCheck::query()->sole();
    expect($check->outcome)->toBe('warning')->and($check->stage)->toBe('create')->and($check->requested_amount)->toBe('15000.00')->and($check->source_type)->toBe('purchase_order');
});

test('submitting for approval is stopped at the lock and the approval stage is checked again', function (): void {
    $rule = ctlRule($this->tenant, 'hard_block', ['stages' => ['submit']]);
    $order = pcOrder(['status' => 'draft', 'total_net' => '15000.00', 'created_by' => $this->owner->id]);

    expect(fn () => app(ProcurementApprovalService::class)->submit($order, $this->owner))->toThrow(ProcurementRuleViolation::class);
    expect($order->refresh()->status)->toBe('draft');

    // Only the approval stage is locked: submitting passes the first check and the approval itself is refused, nothing is half done.
    $rule->update(['stages' => ['approve']]);
    expect(fn () => app(ProcurementApprovalService::class)->submit($order, $this->owner))->toThrow(ProcurementRuleViolation::class);
    expect($order->refresh()->status)->toBe('draft');
});

test('the reconcile command repairs the commitments of every company from purchasing', function (): void {
    pcRequisition(['total_estimated_amount' => '1000.00']);
    $order = pcOrder(['total_net' => '2000.00']);
    $stale = pcOrder(['total_net' => '500.00']);
    $this->sync->syncOrder($stale);
    $stale->update(['status' => 'cancelled']);

    Artisan::call('budgeting:reconcile-commitments', ['--tenant' => (string) $this->tenant->id]);

    $bySource = BudgetCommitment::query()->get()->keyBy('source_id');
    expect($bySource[$order->id]->openAmount())->toBe('2000.00')->and($bySource[$stale->id]->status)->toBe('released')->and(pcOpen())->toBe('3000.00');

    $other = ctlTenant('Other');
    Artisan::call('budgeting:reconcile-commitments');
    expect(BudgetCommitment::withoutGlobalScopes()->where('tenant_id', $other->id)->count())->toBe(0);
});

test('a document of another company never reaches this budget', function (): void {
    $foreign = ctlTenant('Foreign');
    $foreignCenter = ctlCostCenter($foreign);
    app(TenantContext::class)->runWithTenant($foreign, function () use ($foreign, $foreignCenter): void {
        $order = PurchaseOrder::factory()->create(['tenant_id' => $foreign->id, 'cost_center_id' => $foreignCenter->id, 'status' => 'approved', 'date' => '2028-03-10', 'total_net' => '9999.00', 'currency' => 'EUR']);
        $this->sync->syncOrder($order);
        expect(BudgetCommitment::query()->count())->toBe(1);
    });

    expect(BudgetCommitment::query()->count())->toBe(0)->and(pcOpen())->toBe('0.00');
});
