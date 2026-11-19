<?php

declare(strict_types=1);

namespace Tests\Unit\CRM;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\LeadIntakeServiceInterface;
use Modules\CRM\Contracts\MetaLeadGraphClientInterface;
use Modules\CRM\Models\LeadSource;
use Modules\CRM\Services\Intake\LinkedInLeadGenAdapter;
use Modules\CRM\Services\Intake\MetaLeadAdsAdapter;
use Modules\CRM\Services\Intake\WebflowAdapter;
use Modules\CRM\Services\Intake\WebFormAdapter;
use Modules\CRM\Services\Intake\WordPressAdapter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Adapter Tenant', 'legal_name' => 'Adapter Tenant Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->secret = 'test-webhook-secret-0123456789';
    $this->apiKey = 'ffl_test_browser_key_0123456789';

    $this->makeSource = fn (string $type, array $attributes = []): LeadSource => LeadSource::create(array_merge([
        'tenant_id' => (string) $this->tenant->id,
        'code' => $type.'-'.uniqid(),
        'name' => ucfirst($type),
        'type' => $type,
        'is_active' => true,
        'webhook_secret' => $this->secret,
        'api_key_prefix' => substr($this->apiKey, 0, 12),
        'api_key_hash' => hash('sha256', $this->apiKey),
        'allowed_origins' => ['https://shop.example.com'],
    ], $attributes));

    $this->request = function (string $body, array $headers = [], array $parameters = []): Request {
        // Browser forms post url-encoded fields (read by $request->input()); providers post JSON.
        $server = ['CONTENT_TYPE' => $body === '' ? 'application/x-www-form-urlencoded' : 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/api/v1/webhooks/crm/leads/x', 'POST', $parameters, [], [], $server, $body);
    };
});

test('verifies the generic timestamped HMAC signature', function (): void {
    $source = ($this->makeSource)('web_form');
    $adapter = app(WebFormAdapter::class);
    $body = '{"email":"a@b.it"}';
    $ts = (string) now()->getTimestamp();
    $signature = 'sha256='.hash_hmac('sha256', $ts.'.'.$body, $this->secret);

    expect($adapter->verify(($this->request)($body, ['X-Signature' => $signature, 'X-Timestamp' => $ts]), $source))->toBeTrue()
        ->and($adapter->verify(($this->request)($body.' ', ['X-Signature' => $signature, 'X-Timestamp' => $ts]), $source))->toBeFalse()
        ->and($adapter->verify(($this->request)($body, ['X-Signature' => 'sha256='.hash_hmac('sha256', $ts.'.'.$body, 'wrong'), 'X-Timestamp' => $ts]), $source))->toBeFalse();

    $stale = (string) now()->subMinutes(6)->getTimestamp();
    $staleSignature = 'sha256='.hash_hmac('sha256', $stale.'.'.$body, $this->secret);
    expect($adapter->verify(($this->request)($body, ['X-Signature' => $staleSignature, 'X-Timestamp' => $stale]), $source))->toBeFalse();
});

test('verifies browser API key with allowed origin and empty honeypot', function (): void {
    $source = ($this->makeSource)('web_form');
    $adapter = app(WebFormAdapter::class);

    $valid = ($this->request)('', ['X-Api-Key' => $this->apiKey, 'Origin' => 'https://shop.example.com/'], ['_hp' => '']);
    $honeypot = ($this->request)('', ['X-Api-Key' => $this->apiKey, 'Origin' => 'https://shop.example.com'], ['_hp' => 'i am a bot']);
    $wrongOrigin = ($this->request)('', ['X-Api-Key' => $this->apiKey, 'Origin' => 'https://evil.example.com'], []);
    $wrongKey = ($this->request)('', ['X-Api-Key' => 'ffl_nope', 'Origin' => 'https://shop.example.com'], []);

    expect($adapter->verify($valid, $source))->toBeTrue()
        ->and($adapter->verify($honeypot, $source))->toBeFalse()
        ->and($adapter->verify($wrongOrigin, $source))->toBeFalse()
        ->and($adapter->verify($wrongKey, $source))->toBeFalse();
});

test('maps web form fields, aliases, tenant field mapping and custom fields', function (): void {
    $source = ($this->makeSource)('web_form', [
        'field_mapping' => ['ragione' => 'company_name'],
        'default_product_line' => 'ERP',
    ]);

    $dto = app(WebFormAdapter::class)->toDto([
        'submission_id' => 'sub-1',
        'Your-Email' => 'Mario.Rossi@Acme.IT',
        'name' => 'Mario Rossi',
        'Telefono' => '333 1234567',
        'P.IVA' => 'IT01234567890',
        'ragione' => 'Acme Srl',
        'employees' => '51-200',
        'budget' => '15.000',
        'message' => 'Please call me',
        '_hp' => '',
        'tenant_id' => 'ignored-client-value',
    ], $source);

    expect($dto->tenantId)->toBe((string) $this->tenant->id)
        ->and($dto->leadSourceId)->toBe((string) $source->id)
        ->and($dto->externalId)->toBe('sub-1')
        ->and($dto->email)->toBe('Mario.Rossi@Acme.IT')
        ->and($dto->firstName)->toBe('Mario')
        ->and($dto->lastName)->toBe('Rossi')
        ->and($dto->phone)->toBe('333 1234567')
        ->and($dto->vatNumber)->toBe('IT01234567890')
        ->and($dto->companyName)->toBe('Acme Srl')
        ->and($dto->employeeCount)->toBe(51)
        ->and($dto->estimatedBudget)->toBe(15000.0)
        ->and($dto->country)->toBe('IT')
        ->and($dto->productLine)->toBe('ERP')
        ->and($dto->customFields)->toHaveKey('message', 'Please call me')
        ->and($dto->customFields)->not->toHaveKey('_hp');
});

test('maps WordPress field lists and builds a form scoped external id', function (): void {
    $source = ($this->makeSource)('wordpress');

    $dto = app(WordPressAdapter::class)->toDto([
        'form_id' => 7,
        'entry_id' => 99,
        'fields' => [
            ['name' => 'Email', 'value' => 'info@beta.ch'],
            ['label' => 'Company', 'value' => 'Beta SA'],
            ['name' => 'Country', 'value' => 'ch'],
        ],
    ], $source);

    expect($dto->externalId)->toBe('7:99')
        ->and($dto->email)->toBe('info@beta.ch')
        ->and($dto->companyName)->toBe('Beta SA')
        ->and($dto->country)->toBe('CH');
});

test('verifies the native Webflow signature and maps form submissions', function (): void {
    $source = ($this->makeSource)('webflow');
    $adapter = app(WebflowAdapter::class);
    $payload = ['triggerType' => 'form_submission', 'payload' => ['id' => 'wf-123', 'name' => 'Contact', 'data' => ['First Name' => 'Elisa', 'Last Name' => 'Galli', 'Email' => 'elisa@galli.it']]];
    $body = (string) json_encode($payload);
    $ts = (string) (now()->getTimestamp() * 1000);

    $request = ($this->request)($body, ['x-webflow-timestamp' => $ts, 'x-webflow-signature' => hash_hmac('sha256', $ts.':'.$body, $this->secret)]);
    $dto = $adapter->toDto($payload, $source);

    expect($adapter->verify($request, $source))->toBeTrue()
        ->and($dto->externalId)->toBe('wf-123')
        ->and($dto->firstName)->toBe('Elisa')
        ->and($dto->lastName)->toBe('Galli')
        ->and($dto->email)->toBe('elisa@galli.it');
});

test('verifies Meta signatures and maps expanded field_data', function (): void {
    $source = ($this->makeSource)('meta_lead_ads');
    $adapter = app(MetaLeadAdsAdapter::class);
    $payload = ['id' => 'meta-lead-1', 'field_data' => [
        ['name' => 'full_name', 'values' => ['Anna Neri']],
        ['name' => 'phone_number', 'values' => ['+41791234567']],
        ['name' => 'company_name', 'values' => ['Neri AG']],
        ['name' => 'job_title', 'values' => ['CFO']],
    ]];
    $body = (string) json_encode($payload);

    $request = ($this->request)($body, ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $this->secret)]);
    $tampered = ($this->request)($body, ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body.'x', $this->secret)]);
    $dto = $adapter->toDto($payload, $source);

    expect($adapter->verify($request, $source))->toBeTrue()
        ->and($adapter->verify($tampered, $source))->toBeFalse()
        ->and($dto->externalId)->toBe('meta-lead-1')
        ->and($dto->firstName)->toBe('Anna')
        ->and($dto->lastName)->toBe('Neri')
        ->and($dto->phone)->toBe('+41791234567')
        ->and($dto->companyName)->toBe('Neri AG')
        ->and($dto->title)->toBe('CFO');
});

test('expands bare Meta leadgen notifications through the Graph client', function (): void {
    $source = ($this->makeSource)('meta_lead_ads');
    app()->instance(MetaLeadGraphClientInterface::class, new class implements MetaLeadGraphClientInterface
    {
        public function fetchLead(LeadSource $source, string $leadgenId): ?array
        {
            return $leadgenId === 'lg-42' ? ['id' => 'lg-42', 'field_data' => [['name' => 'email', 'values' => ['graph@lead.it']]]] : null;
        }
    });

    $dto = app(MetaLeadAdsAdapter::class)->toDto(['object' => 'page', 'entry' => [['changes' => [['value' => ['leadgen_id' => 'lg-42']]]]]], $source);

    expect($dto->externalId)->toBe('lg-42')
        ->and($dto->email)->toBe('graph@lead.it');
});

test('verifies LinkedIn signatures and maps camelCase fields and answers', function (): void {
    $source = ($this->makeSource)('linkedin_lead_gen');
    $adapter = app(LinkedInLeadGenAdapter::class);
    $flat = ['leadId' => 'li-1', 'firstName' => 'Paolo', 'lastName' => 'Conti', 'emailAddress' => 'paolo@conti.it', 'companyName' => 'Conti Spa', 'companySize' => 'SIZE_51_TO_200'];
    $body = (string) json_encode($flat);

    $prefixed = ($this->request)($body, ['X-LI-Signature' => 'hmacsha256='.hash_hmac('sha256', $body, $this->secret)]);
    $bare = ($this->request)($body, ['X-LI-Signature' => hash_hmac('sha256', $body, $this->secret)]);
    $dto = $adapter->toDto($flat, $source);
    $answers = $adapter->toDto(['id' => 'li-2', 'answers' => [['name' => 'emailAddress', 'answer' => 'x@y.it'], ['question' => 'phoneNumber', 'value' => '0911234567']]], $source);

    expect($adapter->verify($prefixed, $source))->toBeTrue()
        ->and($adapter->verify($bare, $source))->toBeTrue()
        ->and($dto->externalId)->toBe('li-1')
        ->and($dto->firstName)->toBe('Paolo')
        ->and($dto->email)->toBe('paolo@conti.it')
        ->and($dto->companyName)->toBe('Conti Spa')
        ->and($dto->employeeCount)->toBe(51)
        ->and($answers->externalId)->toBe('li-2')
        ->and($answers->email)->toBe('x@y.it')
        ->and($answers->phone)->toBe('0911234567');
});

test('selects the adapter from the source type', function (string $type, string $adapter): void {
    expect(app(LeadIntakeServiceInterface::class)->adapterFor(($this->makeSource)($type)))->toBeInstanceOf($adapter);
})->with([
    ['web_form', WebFormAdapter::class],
    ['manual', WebFormAdapter::class],
    ['csv', WebFormAdapter::class],
    ['wordpress', WordPressAdapter::class],
    ['webflow', WebflowAdapter::class],
    ['meta_lead_ads', MetaLeadAdsAdapter::class],
    ['linkedin_lead_gen', LinkedInLeadGenAdapter::class],
]);
