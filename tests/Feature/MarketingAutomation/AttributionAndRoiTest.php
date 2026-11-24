<?php

declare(strict_types=1);

require_once __DIR__.'/MarketingTestHelpers.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Invoicing\Events\SalesOrderCreatedEvent;
use Modules\MarketingAutomation\Application\Commands\AttributeSalesOrderToMarketingCampaignCommand;
use Modules\MarketingAutomation\Application\Commands\AttributeSalesOrderToMarketingCampaignHandler;
use Modules\MarketingAutomation\Application\Queries\GetMarketingRoiDashboardHandler;
use Modules\MarketingAutomation\Application\Queries\GetMarketingRoiDashboardQuery;
use Modules\MarketingAutomation\Listeners\AttributeSalesDocumentListener;
use Modules\MarketingAutomation\Models\Attribution;
use Modules\MarketingAutomation\Models\CampaignMetric;
use Modules\MarketingAutomation\Services\AttributionService;
use Modules\MarketingAutomation\Services\MetricsService;

test('an order after a clicked e-mail is attributed with net revenue, margin and ROI', function (): void {
    [$tenant] = maSetup('CH');
    $contact = maContact($tenant);
    $campaign = maCampaign($tenant, ['campaign_cost' => 500]);
    maMessage($tenant, $campaign, $contact, now()->subDays(3), now()->subDays(3));

    $order = maSalesOrder($tenant, $contact, now()->toDateString(), 3000);
    maOrderLine($tenant, $order, null, 2, 2000, 600); // margin 800
    maOrderLine($tenant, $order, null, 1, 1000, 400); // margin 600

    $attribution = app(AttributeSalesOrderToMarketingCampaignHandler::class)->handle(new AttributeSalesOrderToMarketingCampaignCommand((string) $tenant->id, 'sales_order', $order));

    expect($attribution)->not->toBeNull()
        ->and($attribution->campaign_id)->toBe($campaign->id)
        ->and((float) $attribution->amount_net)->toBe(3000.0)
        ->and((float) $attribution->gross_margin)->toBe(1400.0)
        ->and($attribution->via)->toBe('touch');

    $metric = CampaignMetric::withoutGlobalScopes()->where('campaign_id', $campaign->id)->first();
    expect($metric->attributed_sales_orders_count)->toBe(1)
        ->and((float) $metric->attributed_net_revenue_amount)->toBe(3000.0)
        ->and((float) $metric->attributed_gross_margin_amount)->toBe(1400.0)
        ->and((float) $metric->campaign_cost)->toBe(500.0)
        ->and((float) $metric->calculated_roi_percentage)->toBe(180.0) // (1400 - 500) / 500
        ->and($metric->roi_state)->toBe('computed');
});

test('attribution is idempotent per document', function (): void {
    [$tenant] = maSetup('CH');
    $contact = maContact($tenant);
    $campaign = maCampaign($tenant);
    maMessage($tenant, $campaign, $contact, now()->subDays(3), null, now()->subDays(2));
    $order = maSalesOrder($tenant, $contact, now()->toDateString(), 1000);
    $service = app(AttributionService::class);

    $service->attribute((string) $tenant->id, 'sales_order', $order);
    $service->attribute((string) $tenant->id, 'sales_order', $order);

    expect(Attribution::withoutGlobalScopes()->where('document_id', $order)->count())->toBe(1)
        ->and(CampaignMetric::withoutGlobalScopes()->where('campaign_id', $campaign->id)->value('attributed_sales_orders_count'))->toBe(1);
});

test('without cost the ROI is null and never a division by zero; with cost and no margin it is -100 %', function (): void {
    [$tenant] = maSetup('CH');
    $free = maCampaign($tenant, ['campaign_cost' => 0]);
    $paid = maCampaign($tenant, ['campaign_cost' => 250]);

    $freeMetric = app(MetricsService::class)->recompute($free);
    $paidMetric = app(MetricsService::class)->recompute($paid);

    expect($freeMetric->calculated_roi_percentage)->toBeNull()->and($freeMetric->roi_state)->toBe('no_cost')
        ->and((float) $paidMetric->calculated_roi_percentage)->toBe(-100.0)->and($paidMetric->roi_state)->toBe('no_revenue');
});

test('last touch picks the latest engaged campaign, first touch the earliest', function (): void {
    [$tenant] = maSetup('CH');
    $contact = maContact($tenant);
    $early = maCampaign($tenant, ['attribution_model' => 'first_touch']);
    $late = maCampaign($tenant, ['attribution_model' => 'first_touch']);
    maMessage($tenant, $early, $contact, now()->subDays(10), now()->subDays(9));
    maMessage($tenant, $late, $contact, now()->subDays(4), now()->subDays(3));

    $firstTouchOrder = maSalesOrder($tenant, $contact, now()->toDateString(), 100);
    expect(app(AttributionService::class)->attribute((string) $tenant->id, 'sales_order', $firstTouchOrder)->campaign_id)->toBe($early->id);

    $late->forceFill(['attribution_model' => 'last_touch'])->save();
    $mixedOrder = maSalesOrder($tenant, $contact, now()->toDateString(), 200);
    expect(app(AttributionService::class)->attribute((string) $tenant->id, 'sales_order', $mixedOrder)->campaign_id)->toBe($late->id);
});

test('engagement outside the attribution window, an unengaged message or a later touch do not attribute', function (): void {
    [$tenant] = maSetup('CH');
    $contact = maContact($tenant);
    $campaign = maCampaign($tenant, ['attribution_window_days' => 7]);
    maMessage($tenant, $campaign, $contact, now()->subDays(20), now()->subDays(19));
    $expired = maSalesOrder($tenant, $contact, now()->toDateString(), 100);

    $silent = maContact($tenant);
    maMessage($tenant, $campaign, $silent, now()->subDays(2));
    $silentOrder = maSalesOrder($tenant, $silent, now()->toDateString(), 100);

    $earlyBird = maContact($tenant);
    maMessage($tenant, $campaign, $earlyBird, now()->addDay(), now()->addDay());
    $beforeSend = maSalesOrder($tenant, $earlyBird, now()->toDateString(), 100);

    $service = app(AttributionService::class);
    expect($service->attribute((string) $tenant->id, 'sales_order', $expired))->toBeNull()
        ->and($service->attribute((string) $tenant->id, 'sales_order', $silentOrder))->toBeNull()
        ->and($service->attribute((string) $tenant->id, 'sales_order', $beforeSend))->toBeNull();
});

test('a coupon code of the campaign on the order attributes it even without a tracked click', function (): void {
    [$tenant] = maSetup('CH');
    $contact = maContact($tenant);
    $campaign = maCampaign($tenant, ['coupon_code' => 'SPRING26']);
    $order = maSalesOrder($tenant, $contact, now()->toDateString(), 900, 'CHF', 'Coupon spring26 applied');

    $attribution = app(AttributionService::class)->attribute((string) $tenant->id, 'sales_order', $order);

    expect($attribution->campaign_id)->toBe($campaign->id)->and($attribution->via)->toBe('coupon');
});

test('quotes, orders and invoices all count; collected amounts follow the payments', function (): void {
    [$tenant] = maSetup('CH');
    $contact = maContact($tenant);
    $campaign = maCampaign($tenant);
    maMessage($tenant, $campaign, $contact, now()->subDays(3), now()->subDays(2));
    $service = app(AttributionService::class);

    $quoteId = (string) Str::uuid7();
    DB::table('quotes')->insert(['id' => $quoteId, 'tenant_id' => (string) $tenant->id, 'contact_id' => $contact, 'quote_number' => 'Q-1', 'date' => now()->toDateString(), 'currency' => 'CHF', 'total_net' => 4000, 'total_tax' => 0, 'total_gross' => 4000, 'created_at' => now(), 'updated_at' => now()]);
    $order = maSalesOrder($tenant, $contact, now()->toDateString(), 4000);
    $invoice = maInvoice($tenant, $contact, now()->toDateString(), 4000, $order, 'CHF', 1000);

    $service->attribute((string) $tenant->id, 'quote', $quoteId);
    $service->attribute((string) $tenant->id, 'sales_order', $order);
    $attribution = $service->attribute((string) $tenant->id, 'invoice', $invoice);

    $metric = CampaignMetric::withoutGlobalScopes()->where('campaign_id', $campaign->id)->first();
    expect($metric->attributed_quotes_count)->toBe(1)
        ->and((float) $metric->attributed_quotes_value)->toBe(4000.0)
        ->and((float) $metric->attributed_invoiced_amount)->toBe(4000.0)
        ->and((float) $metric->attributed_collected_amount)->toBe(1000.0)
        // The invoice of an attributed order is its invoicing step, not a second sale.
        ->and((float) $metric->attributed_net_revenue_amount)->toBe(4000.0);

    DB::table('invoices')->where('id', $invoice)->update(['total_paid' => 4000]);
    $service->refreshCollected($attribution->refresh());

    expect((float) CampaignMetric::withoutGlobalScopes()->where('campaign_id', $campaign->id)->value('attributed_collected_amount'))->toBe(4000.0);
});

test('a direct invoice without a sales order is a sale of its own', function (): void {
    [$tenant] = maSetup('CH');
    $contact = maContact($tenant);
    $campaign = maCampaign($tenant);
    maMessage($tenant, $campaign, $contact, now()->subDays(3), now()->subDays(2));

    app(AttributionService::class)->attribute((string) $tenant->id, 'invoice', maInvoice($tenant, $contact, now()->toDateString(), 700));

    expect((float) CampaignMetric::withoutGlobalScopes()->where('campaign_id', $campaign->id)->value('attributed_net_revenue_amount'))->toBe(700.0);
});

test('EUR documents are normalised to the currency of the campaign', function (): void {
    [$tenant] = maSetup('CH');
    maRate($tenant, 'EUR', 'CHF', '0.950000');
    $contact = maContact($tenant);
    $campaign = maCampaign($tenant, ['currency' => 'CHF']);
    maMessage($tenant, $campaign, $contact, now()->subDays(3), now()->subDays(2));
    $order = maSalesOrder($tenant, $contact, now()->toDateString(), 1000, 'EUR');

    $attribution = app(AttributionService::class)->attribute((string) $tenant->id, 'sales_order', $order);

    expect((float) $attribution->amount_net)->toBe(950.0)->and($attribution->currency)->toBe('CHF');
});

test('the sales listeners feed the attribution without ever breaking the sale', function (): void {
    [$tenant] = maSetup('CH');
    $contact = maContact($tenant);
    $campaign = maCampaign($tenant);
    maMessage($tenant, $campaign, $contact, now()->subDays(3), now()->subDays(2));
    $order = maSalesOrder($tenant, $contact, now()->toDateString(), 1500);

    $listener = app(AttributeSalesDocumentListener::class);
    $listener->handleOrderCreated(new SalesOrderCreatedEvent($order, 'SO-1', $contact, 1500.0, 'CHF', (string) $tenant->id, (string) $tenant->id));

    expect(Attribution::withoutGlobalScopes()->where('document_id', $order)->exists())->toBeTrue();

    // An unknown document is silently ignored.
    $listener->handleInvoicePaid(new InvoicePaidEvent((string) Str::uuid7(), 'X', $contact, 'CHF', 10.0, 10.0, now()->toDateString(), (string) $tenant->id, (string) $tenant->id));
    expect(Attribution::withoutGlobalScopes()->count())->toBe(1);
});

test('the ROI dashboard totals every campaign in the reporting currency of the tenant', function (): void {
    [$tenant] = maSetup('CH');
    maRate($tenant, 'EUR', 'CHF', '0.900000');
    $contactA = maContact($tenant);
    $contactB = maContact($tenant);
    $chf = maCampaign($tenant, ['currency' => 'CHF', 'campaign_cost' => 100]);
    $eur = maCampaign($tenant, ['currency' => 'EUR', 'campaign_cost' => 100]);
    maMessage($tenant, $chf, $contactA, now()->subDays(3), now()->subDays(2));
    maMessage($tenant, $eur, $contactB, now()->subDays(3), now()->subDays(2));
    app(AttributionService::class)->attribute((string) $tenant->id, 'sales_order', maSalesOrder($tenant, $contactA, now()->toDateString(), 1000));
    app(AttributionService::class)->attribute((string) $tenant->id, 'sales_order', maSalesOrder($tenant, $contactB, now()->toDateString(), 1000, 'EUR'));

    $dashboard = maInTenant($tenant, fn () => app(GetMarketingRoiDashboardHandler::class)->handle(new GetMarketingRoiDashboardQuery((string) $tenant->id)));

    expect($dashboard['currency'])->toBe('CHF')
        ->and($dashboard['campaigns'])->toHaveCount(2)
        ->and($dashboard['totals']['attributed_sales_orders_count'])->toBe(2)
        // 1000 CHF + 1000 EUR at 0.90 = 1900 CHF; costs 100 CHF + 90 CHF.
        ->and($dashboard['totals']['attributed_net_revenue_amount'])->toBe(1900.0)
        ->and($dashboard['totals']['campaign_cost'])->toBe(190.0);
});
