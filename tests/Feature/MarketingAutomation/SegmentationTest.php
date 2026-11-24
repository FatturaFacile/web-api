<?php

declare(strict_types=1);

require_once __DIR__.'/MarketingTestHelpers.php';

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\MarketingAutomation\Application\Commands\EvaluateRfmSegmentationAndBuildAudienceCommand;
use Modules\MarketingAutomation\Application\Commands\EvaluateRfmSegmentationAndBuildAudienceHandler;
use Modules\MarketingAutomation\Application\Queries\GetRfmMatrixHandler;
use Modules\MarketingAutomation\Application\Queries\GetRfmMatrixQuery;
use Modules\MarketingAutomation\Domain\Enums\SuppressionReason;
use Modules\MarketingAutomation\Models\Segment;
use Modules\MarketingAutomation\Models\SegmentMember;
use Modules\MarketingAutomation\Services\SegmentEvaluationService;
use Modules\MarketingAutomation\Services\SuppressionService;

function maSegment(string $tenantId, array $criteria = [], bool $hourly = false): Segment
{
    return Segment::withoutGlobalScopes()->create([
        'tenant_id' => $tenantId, 'name' => 'Segment '.Str::random(4), 'criteria' => $criteria, 'auto_refresh_hourly' => $hourly,
    ]);
}

test('the audience contains only customers with consent, outside the credit semaphore, with RFM labels', function (): void {
    [$tenant] = maSetup('CH');
    $today = Carbon::now()->toDateString();

    $good = maContact($tenant);
    maInvoice($tenant, $good, $today, 5000);
    maConsent($tenant, $good);

    $blocked = maContact($tenant);
    maInvoice($tenant, $blocked, $today, 5000);
    maConsent($tenant, $blocked);
    DB::table('customer_credit_profiles')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'customer_id' => $blocked, 'is_credit_hold' => true, 'created_at' => now(), 'updated_at' => now()]);

    $noConsent = maContact($tenant);
    maInvoice($tenant, $noConsent, $today, 5000);

    $unsubscribed = maContact($tenant);
    maInvoice($tenant, $unsubscribed, $today, 5000);
    maConsent($tenant, $unsubscribed, ['unsubscribed_at' => now(), 'marketing_opt_in' => false]);

    $segment = maSegment((string) $tenant->id);
    $result = app(SegmentEvaluationService::class)->evaluate($segment)->refresh();

    expect($result->eligible_contacts_count)->toBe(1)
        ->and($result->excluded_insolvent_count)->toBe(1)
        ->and($result->excluded_no_consent_count)->toBe(2)
        ->and($result->last_evaluated_at)->not->toBeNull()
        ->and(SegmentMember::withoutGlobalScopes()->where('segment_id', $segment->id)->pluck('contact_id')->all())->toBe([$good])
        ->and(array_sum($result->rfm_breakdown))->toBe(1);
});

test('customers with an open collection case, an open dispute or an overdue balance are excluded', function (): void {
    [$tenant] = maSetup('CH');
    $today = Carbon::now()->toDateString();
    $caseCustomer = maContact($tenant);
    $disputeCustomer = maContact($tenant);
    $overdueCustomer = maContact($tenant);
    $clean = maContact($tenant);

    foreach ([$caseCustomer, $disputeCustomer, $overdueCustomer, $clean] as $id) {
        maInvoice($tenant, $id, $today, 1000);
        maConsent($tenant, $id);
    }

    DB::table('credit_collection_cases')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'customer_id' => $caseCustomer, 'case_number' => 'C-1', 'status' => 'open', 'priority' => 'normal', 'outstanding_amount' => 10, 'currency' => 'CHF', 'opened_on' => $today, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('credit_disputes')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'customer_id' => $disputeCustomer, 'reason_code' => 'quality', 'disputed_amount' => 10, 'status' => 'open', 'opened_on' => $today, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('customer_credit_profiles')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'customer_id' => $overdueCustomer, 'overdue_amount' => 50, 'created_at' => now(), 'updated_at' => now()]);

    $result = app(SegmentEvaluationService::class)->evaluate(maSegment((string) $tenant->id));

    expect($result->excluded_insolvent_count)->toBe(3)
        ->and($result->eligible_contacts_count)->toBe(1);

    // A tolerated overdue balance lets the customer back in.
    $tolerant = app(SegmentEvaluationService::class)->evaluate(maSegment((string) $tenant->id, ['max_overdue_balance' => 100]));
    expect($tolerant->excluded_insolvent_count)->toBe(2);
});

test('Italian customers need the profiling consent to be scored and the marketing consent to be mailed', function (): void {
    [$tenant] = maSetup('IT');
    $today = Carbon::now()->toDateString();
    $both = maContact($tenant);
    $marketingOnly = maContact($tenant);
    $profilingOnly = maContact($tenant);

    foreach ([$both, $marketingOnly, $profilingOnly] as $id) {
        maInvoice($tenant, $id, $today, 1000);
    }
    maConsent($tenant, $both);
    maConsent($tenant, $marketingOnly, ['profiling_opt_in' => false]);
    maConsent($tenant, $profilingOnly, ['marketing_opt_in' => false]);

    $result = app(SegmentEvaluationService::class)->evaluate(maSegment((string) $tenant->id));

    expect($result->eligible_contacts_count)->toBe(1)
        ->and($result->excluded_no_consent_count)->toBe(2);
});

test('the soft opt-in lets an existing Swiss customer in without prior consent', function (): void {
    [$tenant] = maSetup('CH');
    $customer = maContact($tenant);
    maInvoice($tenant, $customer, Carbon::now()->toDateString(), 800);
    maConsent($tenant, $customer, ['marketing_opt_in' => false, 'soft_opt_in_eligible' => true]);

    expect(app(SegmentEvaluationService::class)->evaluate(maSegment((string) $tenant->id))->eligible_contacts_count)->toBe(1);
});

test('suppressed addresses never enter an audience', function (): void {
    [$tenant] = maSetup('CH');
    $customer = maContact($tenant, ['email' => 'Bounced@Example.ch']);
    maInvoice($tenant, $customer, Carbon::now()->toDateString(), 800);
    maConsent($tenant, $customer);
    app(SuppressionService::class)->suppress((string) $tenant->id, 'bounced@example.ch', SuppressionReason::HardBounce, $customer);

    expect(app(SegmentEvaluationService::class)->evaluate(maSegment((string) $tenant->id))->eligible_contacts_count)->toBe(0);
});

test('business filters: recency, frequency, monetary value, locale and RFM label', function (): void {
    [$tenant] = maSetup('CH');
    $recent = maContact($tenant, ['document_language' => 'de']);
    $dormant = maContact($tenant, ['document_language' => 'it']);
    maInvoice($tenant, $recent, Carbon::now()->subDays(5)->toDateString(), 9000);
    maInvoice($tenant, $recent, Carbon::now()->subDays(20)->toDateString(), 9000);
    maInvoice($tenant, $dormant, Carbon::now()->subDays(100)->toDateString(), 300);
    maConsent($tenant, $recent, ['locale' => 'de']);
    maConsent($tenant, $dormant);

    $evaluate = static fn (array $criteria): int => app(SegmentEvaluationService::class)->evaluate(maSegment((string) $tenant->id, $criteria))->eligible_contacts_count;

    expect($evaluate([]))->toBe(2)
        ->and($evaluate(['recency_days_min' => 60]))->toBe(1)
        ->and($evaluate(['recency_days_max' => 30]))->toBe(1)
        ->and($evaluate(['frequency_min' => 2]))->toBe(1)
        ->and($evaluate(['monetary_min' => 10000]))->toBe(1)
        ->and($evaluate(['monetary_max' => 1000]))->toBe(1)
        ->and($evaluate(['locale' => 'de']))->toBe(1)
        ->and($evaluate(['locale' => 'fr']))->toBe(0);
});

test('product category and purchase window filters find the customers due for the annual spare-parts kit', function (): void {
    [$tenant] = maSetup('CH');
    $categoryId = (string) Str::uuid7();
    DB::table('product_categories')->insert(['id' => $categoryId, 'tenant_id' => (string) $tenant->id, 'name' => 'Machinery', 'created_at' => now(), 'updated_at' => now()]);
    $productId = (string) Str::uuid7();
    DB::table('products')->insert(['id' => $productId, 'tenant_id' => (string) $tenant->id, 'sku' => 'MAC-1', 'name' => 'Machine', 'category_id' => $categoryId, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

    $due = maContact($tenant);
    $tooRecent = maContact($tenant);
    $other = maContact($tenant);
    foreach ([$due, $tooRecent, $other] as $id) {
        maInvoice($tenant, $id, Carbon::now()->subDays(10)->toDateString(), 500);
        maConsent($tenant, $id);
    }
    maOrderLine($tenant, maSalesOrder($tenant, $due, Carbon::now()->subMonths(11)->toDateString(), 9000), $productId, 1, 9000, 6000);
    maOrderLine($tenant, maSalesOrder($tenant, $tooRecent, Carbon::now()->subMonths(2)->toDateString(), 9000), $productId, 1, 9000, 6000);

    $segment = maSegment((string) $tenant->id, ['purchased_product_category_ids' => [$categoryId], 'purchased_window_months' => ['min' => 10, 'max' => 12]]);
    $result = app(SegmentEvaluationService::class)->evaluate($segment);

    expect($result->eligible_contacts_count)->toBe(1)
        ->and(SegmentMember::withoutGlobalScopes()->where('segment_id', $segment->id)->value('contact_id'))->toBe($due);

    $bySku = app(SegmentEvaluationService::class)->evaluate(maSegment((string) $tenant->id, ['purchased_skus' => ['MAC-1']]));
    expect($bySku->eligible_contacts_count)->toBe(2);
});

test('the canton or province filter uses the address of the customer', function (): void {
    [$tenant] = maSetup('CH');
    $ticino = maContact($tenant);
    $zurich = maContact($tenant);
    foreach ([$ticino => 'TI', $zurich => 'ZH'] as $id => $canton) {
        maInvoice($tenant, $id, Carbon::now()->toDateString(), 500);
        maConsent($tenant, $id);
        DB::table('contact_addresses')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'contact_id' => $id, 'street' => 'Via Test 1', 'state_canton' => $canton, 'created_at' => now(), 'updated_at' => now()]);
    }

    $result = app(SegmentEvaluationService::class)->evaluate(maSegment((string) $tenant->id, ['geographic_canton_or_province' => ['ti']]));

    expect($result->eligible_contacts_count)->toBe(1);
});

test('re-evaluating replaces the memberships and the RFM matrix reports labels, cells and exclusions', function (): void {
    [$tenant] = maSetup('CH');
    $customer = maContact($tenant);
    maInvoice($tenant, $customer, Carbon::now()->toDateString(), 700);
    maConsent($tenant, $customer);
    $segment = maSegment((string) $tenant->id);

    maInTenant($tenant, function () use ($segment, $tenant): void {
        $handler = app(EvaluateRfmSegmentationAndBuildAudienceHandler::class);
        $handler->handle(new EvaluateRfmSegmentationAndBuildAudienceCommand((string) $tenant->id, (string) $segment->id));
        $handler->handle(new EvaluateRfmSegmentationAndBuildAudienceCommand((string) $tenant->id, (string) $segment->id));

        expect(SegmentMember::query()->where('segment_id', $segment->id)->count())->toBe(1);

        $matrix = app(GetRfmMatrixHandler::class)->handle(new GetRfmMatrixQuery((string) $tenant->id, (string) $segment->id));

        expect($matrix['eligible'])->toBe(1)
            ->and($matrix['labels'])->toHaveCount(7)
            ->and(array_sum(array_column($matrix['labels'], 'count')))->toBe(1)
            ->and($matrix['cells'])->toHaveCount(1)
            ->and($matrix['cells'][0]['count'])->toBe(1);
    });
});

test('the hourly job path only refreshes segments flagged for auto refresh', function (): void {
    [$tenant] = maSetup('CH');
    $customer = maContact($tenant);
    maInvoice($tenant, $customer, Carbon::now()->toDateString(), 700);
    maConsent($tenant, $customer);
    $auto = maSegment((string) $tenant->id, [], true);
    $manual = maSegment((string) $tenant->id, [], false);

    maInTenant($tenant, fn () => app(EvaluateRfmSegmentationAndBuildAudienceHandler::class)->handle(new EvaluateRfmSegmentationAndBuildAudienceCommand((string) $tenant->id, null, true)));

    expect($auto->refresh()->last_evaluated_at)->not->toBeNull()
        ->and($manual->refresh()->last_evaluated_at)->toBeNull();
});
