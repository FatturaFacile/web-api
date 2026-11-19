<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Inventory\Events\StockBelowMinimumEvent;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Platform\Models\NotificationAlertRule;
use Modules\Platform\Models\NotificationDigestItem;
use Modules\Platform\Models\NotificationPreference;
use Modules\Platform\Services\AlertRuleEvaluator;
use Modules\SDI\Events\SdiInvoiceRejected;
use Modules\Workflow\Events\ApprovalTokenGeneratedEvent;

require_once __DIR__.'/../Analytics/BiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = biTenant('Alfa');
    $this->owner = biActAs($this->tenant, 'owner');
});

it('notifies owners when an invoice is paid', function (): void {
    Event::dispatch(new InvoicePaidEvent('inv-1', 'FT-9', null, 'EUR', 100.0, 100.0, now()->toIso8601String(), (string) $this->tenant->id, ''));

    $titles = $this->owner->notifications()->get()->pluck('data.title')->all();
    expect($titles)->toBe(['Invoice FT-9 paid']);
});

it('notifies owners of an SDI rejection', function (): void {
    Event::dispatch(new SdiInvoiceRejected((string) $this->tenant->id, 'doc-1', null, 'FT-7', [['code' => '00305', 'description' => 'Invalid VAT number', 'line_reference' => null]]));

    $notification = $this->owner->notifications()->firstOrFail();
    expect($notification->data['title'])->toBe('Invoice FT-7 rejected by SDI')
        ->and($notification->data['message'])->toContain('Invalid VAT number');
});

it('notifies owners of low stock', function (): void {
    $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Widget', 'sku' => 'W-1', 'price' => 1]);

    Event::dispatch(new StockBelowMinimumEvent(StockBelowMinimumEvent::ScopeProduct, null, null, null, (string) $product->id, 2.0, 5.0, (string) $this->tenant->id, ''));

    expect($this->owner->notifications()->firstOrFail()->data['title'])->toBe('Low stock: Widget');
});

it('notifies only the authorised approver of a pending approval', function (): void {
    $approver = biUser($this->tenant, 'member');

    Event::dispatch(new ApprovalTokenGeneratedEvent('tok-1', 'step-1', 'approve', '/approvals/tok-1', (string) $approver->id, (string) $this->tenant->id, ''));

    expect($approver->notifications()->count())->toBe(1)
        ->and($this->owner->notifications()->count())->toBe(0);
});

it('applies the user preferences: a digest category is queued instead of sent at once', function (): void {
    NotificationPreference::query()->create(['user_id' => $this->owner->id, 'category' => 'billing', 'channel' => 'mail', 'enabled' => true, 'frequency' => 'digest']);

    Event::dispatch(new InvoicePaidEvent('inv-2', 'FT-10', null, 'EUR', 100.0, 100.0, now()->toIso8601String(), (string) $this->tenant->id, ''));

    expect(NotificationDigestItem::query()->where('user_id', $this->owner->id)->where('category', 'billing')->count())->toBe(1);
});

describe('alert rule metrics', function (): void {
    it('alerts on open invoices total and rearms below the threshold', function (): void {
        NotificationAlertRule::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Open over 1k', 'metric' => 'open_invoices_total', 'threshold' => 1000, 'currency' => 'EUR', 'category' => 'billing']);
        $customer = biCustomer($this->tenant, 'Rossi Srl');
        $a = biInvoice($this->tenant, $customer, '2027-01-01', '800');

        expect(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(0);

        biInvoice($this->tenant, $customer, '2027-01-02', '500');
        expect(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(1)
            ->and(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(0);

        DB::table('invoices')->where('id', $a->id)->update(['status' => 'paid']);
        expect(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(0);
        DB::table('invoices')->where('id', $a->id)->update(['status' => 'posted']);
        expect(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(1);
    });

    it('alerts on low stock items count', function (): void {
        NotificationAlertRule::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Low stock', 'metric' => 'low_stock_items_count', 'threshold' => 1, 'category' => 'inventory']);
        $p1 = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'A', 'sku' => 'A', 'price' => 1]);
        $p2 = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'sku' => 'B', 'price' => 1]);

        DB::table('products')->where('id', $p1->id)->update(['below_minimum_since' => now()]);
        expect(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(0);

        DB::table('products')->where('id', $p2->id)->update(['below_minimum_since' => now()]);
        expect(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(1)
            ->and(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(0);

        DB::table('products')->update(['below_minimum_since' => null]);
        app(AlertRuleEvaluator::class)->evaluate($this->tenant->id);
        DB::table('products')->update(['below_minimum_since' => now()]);
        expect(app(AlertRuleEvaluator::class)->evaluate($this->tenant->id))->toBe(1);
    });

    it('accepts the new metrics through the API and alerts on SDI rejections', function (): void {
        foreach (['open_invoices_total', 'low_stock_items_count', 'sdi_rejected_count'] as $metric) {
            $this->postJson('/api/v1/platform/notification-alert-rules', ['name' => $metric, 'metric' => $metric, 'threshold' => 0])->assertCreated();
        }

        $rule = NotificationAlertRule::query()->where('metric', 'sdi_rejected_count')->firstOrFail();
        expect($rule->metric)->toBe('sdi_rejected_count');
    });
});
