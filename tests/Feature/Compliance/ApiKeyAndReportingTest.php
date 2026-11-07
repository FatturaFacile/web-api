<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\Models\ApiKey;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

function reportingInvoice(Tenant $tenant, string $number, string $date = '2027-02-10'): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => '=HYPERLINK("x")', 'email' => 'a@example.com']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => $number, 'date' => $date, 'due_date' => $date, 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();

    return $invoice;
}

function issueKey(array $payload = []): array
{
    return test()->postJson('/api/v1/integrations/api-keys', ['name' => 'Power BI', 'scopes' => ['reporting.read'], ...$payload])->assertCreated()->json('data');
}

/**
 * A request with the key only: no Sanctum user and no tenant header.
 */
function feed(string $token, string $uri = '/api/v1/reporting/feed/invoices')
{
    app('auth')->forgetGuards();
    app(TenantContext::class)->clear();

    return test()->withHeaders(['Authorization' => "Bearer {$token}", 'X-Tenant-Id' => ''])->getJson($uri);
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    $this->admin = actingAsMember($this->tenant);
    reportingInvoice($this->tenant, 'FT-1');
    reportingInvoice($this->tenant, 'FT-2');
});

test('a key is shown once and stored only as a hash', function (): void {
    $key = issueKey();

    expect($key['token'])->toStartWith('ffk_')
        ->and($key)->not->toHaveKey('key_hash')
        ->and(DB::table('api_keys')->value('key_hash'))->toBe(hash('sha256', $key['token']))
        ->and($this->getJson('/api/v1/integrations/api-keys')->assertOk()->getContent())->not->toContain($key['token']);
});

test('the feed returns only the tenant of the key, even if another tenant is asked for', function (): void {
    $token = issueKey()['token'];
    $other = complianceTenant('Beta');
    reportingInvoice($other, 'FT-OTHER');

    $response = $this->withHeaders(['X-Tenant-Id' => $other->id]);
    $body = feed($token)->assertOk()->json();

    expect($body['@odata.count'])->toBe(2)
        ->and(array_column($body['value'], 'invoice_number'))->toEqualCanonicalizing(['FT-1', 'FT-2']);
});

test('the feed pages with top and skip and exposes only the allowed columns', function (): void {
    $token = issueKey()['token'];

    $first = feed($token, '/api/v1/reporting/feed/invoices?$top=1')->assertOk()->json();

    expect($first['value'])->toHaveCount(1)
        ->and($first['@odata.nextLink'])->toContain('%24skip=1')
        ->and(array_keys($first['value'][0]))->not->toContain('tenant_id');

    expect(feed($token, '/api/v1/reporting/feed/invoices?$top=1&$skip=1')->json())->not->toHaveKey('@odata.nextLink');
    expect(feed($token, '/api/v1/reporting/feed/contacts')->assertOk()->json()['@odata.count'])->toBe(2);
    expect(feed($token, '/api/v1/reporting/feed/purchases')->assertOk()->json()['@odata.count'])->toBe(0);
});

test('last use is tracked', function (): void {
    $key = issueKey();

    feed($key['token'])->assertOk();

    expect(ApiKey::withoutGlobalScopes()->find($key['id'])->last_used_at)->not->toBeNull();
});

test('revoked, expired, unknown and user tokens are refused', function (): void {
    $revoked = issueKey();
    $expired = issueKey(['expires_at' => now()->addHour()->toIso8601String()]);
    $this->deleteJson("/api/v1/integrations/api-keys/{$revoked['id']}")->assertOk();
    $this->travel(2)->hours();

    feed($revoked['token'])->assertUnauthorized();
    feed($expired['token'])->assertUnauthorized();
    feed('ffk_unknown')->assertUnauthorized();
    feed($this->admin->createToken('user')->plainTextToken)->assertUnauthorized();
});

test('module access still decides: a key without the scope gets a 403', function (): void {
    $key = issueKey();
    DB::table('api_keys')->where('id', $key['id'])->update(['scopes' => json_encode(['exports.read'])]);

    feed($key['token'])->assertForbidden();
});

test('a key does not open any other route', function (): void {
    $token = issueKey()['token'];

    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/v1/sales/invoices')->assertUnauthorized();
});

test('unknown scopes are refused and the management API is for owners and admins only', function (string $role): void {
    $this->postJson('/api/v1/integrations/api-keys', ['name' => 'X', 'scopes' => ['*']])->assertUnprocessable();

    actingAsMember($this->tenant, $role);
    $this->getJson('/api/v1/integrations/api-keys')->assertForbidden();
    $this->postJson('/api/v1/integrations/api-keys', ['name' => 'X', 'scopes' => ['reporting.read']])->assertForbidden();
})->with(['accountant', 'accountant_readonly', 'member']);

test('keys of another tenant cannot be listed or revoked', function (): void {
    $key = issueKey();

    actingAsMember(complianceTenant('Beta'));

    $this->deleteJson("/api/v1/integrations/api-keys/{$key['id']}")->assertNotFound();
    expect($this->getJson('/api/v1/integrations/api-keys')->json('data'))->toBe([]);
});

test('the CSV export opens in Excel and neutralises formulas', function (string $dataset, string $expected): void {
    actingAsMember($this->tenant, 'accountant_readonly');

    $content = $this->get("/api/v1/exports/csv/{$dataset}")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();

    expect($content)->toStartWith("\xEF\xBB\xBF")->toContain(';')->toContain($expected);
})->with([
    ['invoices', '"FT-1"'],
    ['contacts', "\"'=HYPERLINK(\"\"x\"\")\""],
    ['purchases', '"invoice_number"'],
]);

test('the CSV export follows the tenant and the role', function (): void {
    actingAsMember(complianceTenant('Beta'));
    expect($this->get('/api/v1/exports/csv/invoices')->getContent())->not->toContain('FT-1');

    actingAsMember($this->tenant, 'member');
    $this->getJson('/api/v1/exports/csv/invoices')->assertForbidden();
});
