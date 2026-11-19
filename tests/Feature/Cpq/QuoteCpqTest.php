<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Quote;
use App\Models\QuoteApprovalTier;
use App\Models\QuoteItem;
use App\Models\QuoteSignatureRequest;
use App\Models\Tenant;
use App\Models\Translation;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\CpqDefaultCatalogAndRulesSeeder;
use Database\Seeders\CpqLocalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const CPQ_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Impianti Srl', 'legal_name' => 'Impianti Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Cliente SpA', 'country' => 'IT']);
    $this->seed(CpqDefaultCatalogAndRulesSeeder::class);
});

function cpqUser(string $role): User
{
    $user = User::create(['name' => ucfirst($role), 'email' => Str::random(10).'@example.it', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach(test()->tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function cpqRoleUser(string $role): User
{
    static $users = [];
    $key = test()->tenant->id.'|'.$role;

    return $users[$key] ??= cpqUser($role);
}

function cpqApi(string $role): mixed
{
    Sanctum::actingAs(cpqRoleUser($role));

    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

/**
 * @param  array<string, mixed>  $overrides
 * @param  list<array<string, mixed>>|null  $items
 */
function cpqQuote(?array $items = null, array $overrides = []): Quote
{
    $items ??= [cpqLine()];
    $response = cpqApi('sales')->postJson('/api/v1/sales/quotes', array_merge([
        'contact_id' => (string) test()->contact->id,
        'date' => '2027-11-23',
        'currency' => 'EUR',
        'items' => $items,
    ], $overrides));
    $response->assertCreated();

    return Quote::query()->with('items')->findOrFail($response->json('data.id'));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cpqLine(array $overrides = []): array
{
    return array_merge(['description' => 'Pressa idraulica', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 8.1, 'discount_percentage' => 0, 'cost_price' => 600, 'labor_cost' => 0], $overrides);
}

test('the cost fields are saved on the line and hidden from the roles that do not see costs', function (): void {
    $quote = cpqQuote([cpqLine(['configuration_snapshot' => ['selection' => ['color' => 'red'], 'labels' => ['Color: Red'], 'cost' => '600.00']])]);

    $manager = cpqApi('sales_manager')->getJson("/api/v1/sales/quotes/{$quote->id}/cpq")->assertOk();
    expect($manager->json('data.items.0.cost_price'))->toBe('600.0000')
        ->and($manager->json('data.items.0.configuration_snapshot.cost'))->toBe('600.00');

    $sales = cpqApi('sales')->getJson("/api/v1/sales/quotes/{$quote->id}/cpq")->assertOk();
    expect($sales->json('data.items.0'))->not->toHaveKeys(['cost_price', 'labor_cost', 'margin_percentage'])
        ->and($sales->json('data.items.0.configuration_snapshot'))->not->toHaveKey('cost')
        ->and($sales->json('data.items.0.configuration_snapshot.labels'))->toBe(['Color: Red']);
});

test('margin and simulator endpoints are reserved to the cost roles', function (): void {
    $quote = cpqQuote();

    cpqApi('sales')->getJson("/api/v1/sales/quotes/{$quote->id}/margin")->assertForbidden();
    cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/simulate", ['mode' => 'discount_percent', 'value' => 5])->assertForbidden();

    $margin = cpqApi('sales_manager')->getJson("/api/v1/sales/quotes/{$quote->id}/margin")->assertOk();
    expect($margin->json('data.totals.margin_percent'))->toBe('40.00')
        ->and($margin->json('data.totals.cost_total'))->toBe('600.00');
});

test('the simulator finds the discount for a target margin and a target price without saving', function (): void {
    $quote = cpqQuote();

    $margin = cpqApi('sales_manager')->postJson("/api/v1/sales/quotes/{$quote->id}/simulate", ['mode' => 'target_margin_percent', 'value' => 25])->assertOk();
    expect($margin->json('data.global_discount_percent'))->toBe('20.00')
        ->and($margin->json('data.simulated.margin_percent'))->toBe('25.00')
        ->and($margin->json('data.achievable'))->toBeTrue();

    $price = cpqApi('sales_manager')->postJson("/api/v1/sales/quotes/{$quote->id}/simulate", ['mode' => 'target_total_price', 'value' => 900])->assertOk();
    expect($price->json('data.global_discount_percent'))->toBe('10.00')
        ->and($price->json('data.simulated.net_total'))->toBe('900.00');

    $cascade = cpqApi('sales_manager')->postJson("/api/v1/sales/quotes/{$quote->id}/simulate", ['mode' => 'discount_percent', 'value' => 30])->assertOk();
    expect($cascade->json('data.simulated.margin_percent'))->toBe('14.29');

    expect($quote->fresh()->total_net)->toBe('1000.00');
});

test('a target margin below the cost is reported as not achievable only when the price cannot reach it', function (): void {
    $quote = cpqQuote([cpqLine(['unit_price' => 100, 'cost_price' => 100])]);

    $response = cpqApi('sales_manager')->postJson("/api/v1/sales/quotes/{$quote->id}/simulate", ['mode' => 'target_margin_percent', 'value' => 30])->assertOk();

    expect($response->json('data.global_discount_percent'))->toBe('0.00')
        ->and($response->json('data.achievable'))->toBeFalse();
});

test('the simulator validates the mode and the value', function (): void {
    $quote = cpqQuote();

    cpqApi('sales_manager')->postJson("/api/v1/sales/quotes/{$quote->id}/simulate", ['mode' => 'nope', 'value' => 5])->assertUnprocessable();
    cpqApi('sales_manager')->postJson("/api/v1/sales/quotes/{$quote->id}/simulate", ['mode' => 'discount_percent', 'value' => 150])->assertUnprocessable();
});

test('a revision keeps the group, supersedes the previous one and records its notes', function (): void {
    $first = cpqQuote();
    $second = Quote::query()->findOrFail(cpqApi('sales')->postJson("/api/v1/sales/quotes/{$first->id}/revise", ['revision_notes' => 'Lower price', 'items' => [cpqLine(['unit_price' => 900])]])->assertCreated()->json('data.id'));

    expect($first->fresh()->status)->toBe('superseded')
        ->and($first->fresh()->is_current_revision)->toBeFalse()
        ->and($second->is_current_revision)->toBeTrue()
        ->and($second->quote_group_uuid)->toBe($first->fresh()->quote_group_uuid)
        ->and($second->revision_notes)->toBe('Lower price')
        ->and($second->items->first()->cost_price)->toBe('600.0000');

    $history = cpqApi('sales')->getJson("/api/v1/sales/quotes/{$second->id}/revisions")->assertOk()->json('data');
    expect($history)->toHaveCount(2)->and(array_column($history, 'is_current_revision'))->toBe([false, true]);
});

test('the revision diff lists added, removed and modified lines with the margin impact', function (): void {
    $first = cpqQuote([cpqLine(), cpqLine(['description' => 'Accessory A', 'unit_price' => 100, 'cost_price' => 40])]);
    $second = Quote::query()->findOrFail(cpqApi('sales')->postJson("/api/v1/sales/quotes/{$first->id}/revise", ['items' => [
        cpqLine(['unit_price' => 900, 'configuration_snapshot' => ['selection' => ['color' => 'blue']]]),
        cpqLine(['description' => 'Accessory B', 'unit_price' => 50, 'cost_price' => 20]),
    ]])->assertCreated()->json('data.id'));

    $diff = cpqApi('sales_manager')->getJson("/api/v1/sales/quotes/{$first->id}/diff/{$second->id}")->assertOk()->json('data');

    expect(array_column($diff['items']['added'], 'description'))->toBe(['Accessory B'])
        ->and(array_column($diff['items']['removed'], 'description'))->toBe(['Accessory A'])
        ->and($diff['items']['modified'][0]['changes'])->toHaveKeys(['unit_price', 'line_total', 'configuration'])
        ->and($diff['totals']['total_net']['delta'])->toBe('-150.00')
        ->and($diff['totals']['margin_amount']['delta'])->toBe('-130.00');

    $restricted = cpqApi('sales')->getJson("/api/v1/sales/quotes/{$first->id}/diff/{$second->id}")->assertOk()->json('data');
    expect($restricted['totals'])->not->toHaveKeys(['cost_total', 'margin_amount', 'margin_percent']);
});

test('only revisions of the same quote can be compared', function (): void {
    $one = cpqQuote();
    $other = cpqQuote();

    cpqApi('sales')->getJson("/api/v1/sales/quotes/{$one->id}/diff/{$other->id}")->assertUnprocessable();
});

test('a quote within the standard terms needs no approval', function (): void {
    $quote = cpqQuote([cpqLine(['discount_percentage' => 5])]);

    $response = cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/approvals/submit")->assertOk();

    expect($response->json('data'))->toBe([])->and($quote->fresh()->status)->toBe('draft');
    cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/signature-links", ['locale' => 'it'])->assertCreated();
});

test('a discount over the first tier needs the sales manager and blocks the customer until approved', function (): void {
    $quote = cpqQuote([cpqLine(['discount_percentage' => 15])]);

    $requests = cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/approvals/submit")->assertOk()->json('data');
    expect($requests)->toHaveCount(1)
        ->and($requests[0]['required_role'])->toBe('sales_manager')
        ->and($requests[0]['triggers'][0]['type'])->toBe('max_line_discount_percent')
        ->and($quote->fresh()->status)->toBe('pending_approval');

    cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/signature-links", ['locale' => 'it'])->assertUnprocessable();
    cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/approvals/approve")->assertForbidden();

    $manager = cpqRoleUser('sales_manager');
    cpqApi('sales_manager')->postJson("/api/v1/sales/quotes/{$quote->id}/approvals/approve")->assertOk();

    $quote->refresh();
    $request = $quote->approvalRequests()->first();
    expect($quote->status)->toBe('approved')
        ->and($request->status)->toBe('approved')
        ->and($request->approved_by)->toBe($manager->id)
        ->and($request->decided_at)->not->toBeNull();
    cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/signature-links", ['locale' => 'it'])->assertCreated();
});

test('a deep discount escalates to the director and a sales manager cannot decide the second level', function (): void {
    $quote = cpqQuote([cpqLine(['discount_percentage' => 25])]);

    $requests = cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/approvals/submit")->assertOk()->json('data');
    expect(array_column($requests, 'level'))->toBe([1, 2])->and(array_column($requests, 'required_role'))->toBe(['sales_manager', 'admin']);

    cpqApi('sales_manager')->postJson("/api/v1/sales/quotes/{$quote->id}/approvals/approve")->assertOk();
    expect($quote->fresh()->status)->toBe('pending_approval');

    cpqApi('sales_manager')->postJson("/api/v1/sales/quotes/{$quote->id}/approvals/approve")->assertUnprocessable();
    cpqApi('admin')->postJson("/api/v1/sales/quotes/{$quote->id}/approvals/approve")->assertOk();

    expect($quote->fresh()->status)->toBe('approved');
});

test('a rejection records who, when and why and closes the other levels', function (): void {
    $quote = cpqQuote([cpqLine(['discount_percentage' => 25])]);
    cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/approvals/submit")->assertOk();

    cpqApi('sales_manager')->postJson("/api/v1/sales/quotes/{$quote->id}/approvals/reject")->assertUnprocessable();
    cpqApi('sales_manager')->postJson("/api/v1/sales/quotes/{$quote->id}/approvals/reject", ['rejection_reason' => 'Margin too thin'])->assertOk();

    $quote->refresh();
    $rejected = $quote->approvalRequests()->get();
    expect($quote->status)->toBe('rejected')
        ->and($rejected->pluck('status')->unique()->all())->toBe(['rejected'])
        ->and($rejected->first()->rejection_reason)->toBe('Margin too thin')
        ->and($rejected->first()->rejected_by)->toBe(cpqRoleUser('sales_manager')->id);
    cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/signature-links", ['locale' => 'it'])->assertUnprocessable();
});

test('a margin below the minimum triggers the approval too', function (): void {
    $quote = cpqQuote([cpqLine(['cost_price' => 900])]);

    $requests = cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/approvals/submit")->assertOk()->json('data');

    expect($requests)->toHaveCount(2)->and($requests[0]['triggers'][0]['type'])->toBe('min_margin_percent');
});

test('a draft that breaches a threshold cannot reach the customer without going through the approval', function (): void {
    $quote = cpqQuote([cpqLine(['discount_percentage' => 15])]);

    cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/signature-links", ['locale' => 'en'])->assertUnprocessable();
});

test('only owners and admins configure the approval tiers', function (): void {
    cpqApi('sales_manager')->postJson('/api/v1/sales/quote-approval-tiers', ['level' => 3, 'name' => 'CEO', 'approver_role' => 'owner', 'max_global_discount_percent' => 40])->assertForbidden();
    $created = cpqApi('admin')->postJson('/api/v1/sales/quote-approval-tiers', ['level' => 3, 'name' => 'CEO', 'approver_role' => 'owner', 'max_global_discount_percent' => 40])->assertCreated();
    cpqApi('admin')->postJson('/api/v1/sales/quote-approval-tiers', ['level' => 3, 'name' => 'Dup', 'approver_role' => 'owner'])->assertUnprocessable();

    cpqApi('sales')->getJson('/api/v1/sales/quote-approval-tiers')->assertOk()->assertJsonCount(3, 'data');
    cpqApi('admin')->deleteJson('/api/v1/sales/quote-approval-tiers/'.$created->json('data.id'))->assertNoContent();
});

/**
 * @return array{0: string, 1: Quote}
 */
function cpqSignatureLink(?Quote $quote = null, string $locale = 'de', int $hours = 24): array
{
    $quote ??= cpqQuote();
    $response = cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/signature-links", ['locale' => $locale, 'validity_hours' => $hours])->assertCreated();

    return [(string) $response->json('data.token'), $quote];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cpqSignPayload(array $overrides = []): array
{
    return array_merge(['signer_name' => 'Anna Keller', 'signer_role' => 'Purchasing, Keller AG', 'purchase_order_number' => 'PO-77', 'signature_type' => 'typed', 'signature_data' => 'Anna Keller', 'accept_terms' => true], $overrides);
}

test('the customer reviews a quote through the public link without seeing cost or margin', function (): void {
    [$token] = cpqSignatureLink(cpqQuote([cpqLine(['configuration_snapshot' => ['labels' => ['Finish: Matt']]])]));

    $response = $this->getJson("/api/v1/public/quote-signatures/{$token}")->assertOk();

    expect($response->json('data.locale'))->toBe('de')
        ->and($response->json('data.quote.items.0.specs'))->toBe(['Finish: Matt'])
        ->and($response->json('data.quote.items.0'))->not->toHaveKeys(['cost_price', 'labor_cost', 'margin_percentage'])
        ->and(json_encode($response->json()))->not->toContain('cost_price');
    $this->getJson('/api/v1/public/quote-signatures/'.str_repeat('a', 64))->assertUnprocessable();
});

test('signing accepts the quote and stores the tamper-evident audit metadata', function (): void {
    [$token, $quote] = cpqSignatureLink();

    $this->withHeader('User-Agent', 'CpqTest/1.0')->postJson("/api/v1/public/quote-signatures/{$token}/sign", cpqSignPayload())->assertOk();

    $quote->refresh();
    $request = $quote->signatureRequests()->firstOrFail();
    expect($quote->status)->toBe('accepted')
        ->and($quote->signed_by)->toBe('Anna Keller')
        ->and($request->status)->toBe('signed')
        ->and($request->signer_role)->toBe('Purchasing, Keller AG')
        ->and($request->purchase_order_number)->toBe('PO-77')
        ->and($request->user_agent)->toBe('CpqTest/1.0')
        ->and($request->ip_address)->not->toBeNull()
        ->and($request->payload_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($request->pdf_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($request->signed_at)->not->toBeNull();

    $verify = cpqApi('sales')->getJson("/api/v1/sales/quotes/{$quote->id}/signature-requests/{$request->id}/verify")->assertOk();
    expect($verify->json('data.valid'))->toBeTrue();
});

test('the signature check fails when the quote changes after it was signed', function (): void {
    [$token, $quote] = cpqSignatureLink();
    $this->postJson("/api/v1/public/quote-signatures/{$token}/sign", cpqSignPayload())->assertOk();
    $request = $quote->signatureRequests()->firstOrFail();

    QuoteItem::query()->where('quote_id', $quote->id)->update(['unit_price' => 1]);

    $verify = cpqApi('sales')->getJson("/api/v1/sales/quotes/{$quote->id}/signature-requests/{$request->id}/verify")->assertOk();
    expect($verify->json('data.valid'))->toBeFalse()->and($verify->json('data.actual_hash'))->not->toBe($request->payload_hash);
});

test('a link works once, expires and can be revoked', function (): void {
    [$token] = cpqSignatureLink();
    $this->postJson("/api/v1/public/quote-signatures/{$token}/sign", cpqSignPayload())->assertOk();
    $this->postJson("/api/v1/public/quote-signatures/{$token}/sign", cpqSignPayload())->assertUnprocessable();

    [$expiring] = cpqSignatureLink(null, 'en', 1);
    $this->travel(2)->hours();
    $this->getJson("/api/v1/public/quote-signatures/{$expiring}")->assertUnprocessable();
    $this->travelBack();

    [$revoked, $quote] = cpqSignatureLink();
    $request = $quote->signatureRequests()->latest()->firstOrFail();
    cpqApi('sales')->postJson("/api/v1/sales/quotes/{$quote->id}/signature-requests/{$request->id}/revoke")->assertOk();
    $this->getJson("/api/v1/public/quote-signatures/{$revoked}")->assertUnprocessable();
});

test('only the hash of the token is stored', function (): void {
    [$token, $quote] = cpqSignatureLink();

    $stored = QuoteSignatureRequest::query()->where('quote_id', $quote->id)->firstOrFail();
    expect($stored->makeVisible('token_hash')->token_hash)->toBe(hash('sha256', $token))->and(str_contains(json_encode($stored->toArray()), $token))->toBeFalse();
});

test('the signature needs the signer details and a valid drawn image', function (): void {
    [$token] = cpqSignatureLink();

    $this->postJson("/api/v1/public/quote-signatures/{$token}/sign", cpqSignPayload(['signer_name' => '']))->assertUnprocessable();
    $this->postJson("/api/v1/public/quote-signatures/{$token}/sign", cpqSignPayload(['accept_terms' => false]))->assertUnprocessable();
    $this->postJson("/api/v1/public/quote-signatures/{$token}/sign", cpqSignPayload(['signature_type' => 'drawn', 'signature_data' => base64_encode('not a png')]))->assertUnprocessable();
    $this->postJson("/api/v1/public/quote-signatures/{$token}/sign", cpqSignPayload(['signature_type' => 'drawn', 'signature_data' => CPQ_PNG]))->assertOk();
});

test('the customer picks the optional lines and the totals follow', function (): void {
    $quote = cpqQuote(
        [cpqLine(), cpqLine(['description' => 'Extra warranty', 'unit_price' => 200, 'cost_price' => 50, 'is_optional' => true, 'is_selected' => false])],
        ['allow_optional_selection' => true]
    );
    expect($quote->total_net)->toBe('1000.00');
    $optional = $quote->items->firstWhere('is_optional', true);
    [$token] = cpqSignatureLink($quote);

    $this->postJson("/api/v1/public/quote-signatures/{$token}/sign", cpqSignPayload(['selected_optional_item_ids' => [$optional->id]]))->assertOk();

    expect($quote->fresh()->total_net)->toBe('1200.00')
        ->and($quote->signatureRequests()->first()->selected_optional_item_ids)->toBe([$optional->id]);
});

test('the optional selection is ignored when the quote does not allow it', function (): void {
    $quote = cpqQuote([cpqLine(), cpqLine(['description' => 'Extra warranty', 'unit_price' => 200, 'is_optional' => true, 'is_selected' => false])]);
    $optional = $quote->items->firstWhere('is_optional', true);
    [$token] = cpqSignatureLink($quote);

    $this->postJson("/api/v1/public/quote-signatures/{$token}/sign", cpqSignPayload(['selected_optional_item_ids' => [$optional->id]]))->assertOk();

    expect($quote->fresh()->total_net)->toBe('1000.00');
});

test('the customer can ask changes instead of signing', function (): void {
    [$token, $quote] = cpqSignatureLink();

    $this->postJson("/api/v1/public/quote-signatures/{$token}/request-changes", ['message' => 'Please add delivery'])->assertOk();

    expect($quote->fresh()->status)->toBe('draft')
        ->and($quote->signatureRequests()->first()->status)->toBe('changes_requested')
        ->and($quote->signatureRequests()->first()->change_request)->toBe('Please add delivery');
    $this->getJson("/api/v1/public/quote-signatures/{$token}")->assertUnprocessable();
});

test('the quote PDF renders in every language with the technical details switched on or off', function (): void {
    $this->seed(CpqLocalizationSeeder::class);
    $quote = cpqQuote([cpqLine(['configuration_snapshot' => ['labels' => ['Color: Red']]])]);

    foreach (['it', 'de', 'fr', 'en'] as $locale) {
        $response = cpqApi('sales')->get("/api/v1/sales/quotes/{$quote->id}/preview-pdf?locale={$locale}&show_technical_details=".($locale === 'en' ? '0' : '1'))->assertOk();
        expect($response->headers->get('Content-Type'))->toBe('application/pdf')->and(str_starts_with($response->getContent(), '%PDF'))->toBeTrue();
    }

    cpqApi('sales')->get("/api/v1/sales/quotes/{$quote->id}/preview-pdf?locale=xx")->assertUnprocessable();
});

test('the localization seeder is idempotent and complete in four languages', function (): void {
    $this->seed(CpqLocalizationSeeder::class);
    $count = Translation::withoutGlobalScopes()->whereNull('tenant_id')->where('key', 'like', 'CPQ.%')->count();
    $this->seed(CpqLocalizationSeeder::class);

    expect(Translation::withoutGlobalScopes()->whereNull('tenant_id')->where('key', 'like', 'CPQ.%')->count())->toBe($count);

    $perLocale = Translation::withoutGlobalScopes()->whereNull('tenant_id')->where(fn ($query) => $query->where('key', 'like', 'CPQ.%')->orWhere('key', 'like', 'PDF.QUOTE.CPQ_%'))->get()->groupBy('locale')->map(fn ($rows) => $rows->count());
    expect($perLocale->keys()->sort()->values()->all())->toBe(['de', 'en', 'fr', 'it'])->and($perLocale->unique())->toHaveCount(1);
});

test('the default approval tiers seeder keeps the levels a company already has', function (): void {
    $this->seed(CpqDefaultCatalogAndRulesSeeder::class);

    expect(QuoteApprovalTier::query()->count())->toBe(2);
});
