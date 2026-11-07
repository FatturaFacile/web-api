<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AiGateway\Contracts\AiDriverInterface;
use Enterprise\AiGateway\DTOs\AiPayload;
use Enterprise\AiGateway\DTOs\AiResponse;
use Enterprise\AiGateway\Services\AiDriverRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Assistant\Catalogue\QueryCatalogue;
use Modules\Assistant\Models\AssistantQuestionLog;
use Modules\Assistant\Services\ReadOnlyQueryRunner;
use Modules\Documents\Models\TenantAiSetting;
use Modules\Treasury\Models\BankAccount;

uses(RefreshDatabase::class);

function assistantMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function assistantActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function assistantInvoice(Tenant $tenant, string $gross, string $date = '2026-03-10', string $paid = '0'): void
{
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'company_name' => 'Cliente '.$tenant->name]);

    DB::table('invoices')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'contact_id' => $contact->id,
        'invoice_number' => 'A-'.Str::random(8),
        'date' => $date,
        'due_date' => $date,
        'total_net' => $gross,
        'total_gross' => $gross,
        'total_paid' => $paid,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function registerAssistantModel(string $answer, bool &$called = false): void
{
    $driver = new class($answer, $called) implements AiDriverInterface
    {
        public function __construct(private readonly string $answer, private bool &$called) {}

        public function getName(): string
        {
            return 'external-test';
        }

        public function executeInference(AiPayload $payload): AiResponse
        {
            $this->called = true;

            return new AiResponse(success: true, content: $this->answer, driverUsed: 'external-test');
        }

        public function generateEmbeddings(string $text, ?string $model = null): array
        {
            return [];
        }
    };

    app(AiDriverRegistry::class)->register('external-test', $driver);
    config(['ai.assistant_driver' => 'external-test']);
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->accountant = assistantMember($this->tenant, 'accountant');
    assistantActAs($this->accountant, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a question is answered from the catalogue with the data of the tenant only', function (): void {
    assistantInvoice($this->tenant, '1000.00');
    assistantInvoice($this->tenant, '500.00', paid: '200');
    assistantInvoice($this->other, '99999.00');

    $data = $this->postJson('/api/v1/assistant/ask', ['question' => 'Quanto ci devono i clienti?'])->assertOk()->json('data');

    expect($data['outcome'])->toBe('answered')
        ->and($data['query'])->toBe('open_receivables')
        ->and($data['rows'])->toHaveCount(1)
        ->and((float) $data['rows'][0]['open_amount'])->toBe(1300.0);
});

test('every catalogue query runs read-only and stays inside the tenant', function (string $question): void {
    assistantInvoice($this->other, '99999.00');
    BankAccount::factory()->create(['tenant_id' => $this->other->id, 'current_balance' => '77777.00']);
    Contact::factory()->create(['tenant_id' => $this->other->id, 'company_name' => 'Foreign']);

    $owner = assistantMember($this->tenant, 'owner');
    assistantActAs($owner, $this->tenant);

    $data = $this->postJson('/api/v1/assistant/ask', ['question' => $question])->assertOk()->json('data');

    expect($data['outcome'])->toBe('answered')->and($data['rows'])->toBe([]);
})->with([
    'crediti aperti',
    'fatture scadute',
    'top clienti 2026',
    'fatturato 2026',
    'da pagare',
    'fatture da approvare',
    'liquidità',
    'contratti in scadenza nei prossimi 30 giorni',
]);

test('every query of the catalogue is covered above and declares a known module', function (): void {
    $catalogue = app(QueryCatalogue::class)->all();

    expect($catalogue)->toHaveCount(8);

    foreach ($catalogue as $query) {
        expect(config("permissions.modules.{$query->module()}"))->toBeArray();
    }
});

test('no catalogue class takes text from the question or builds SQL from a variable', function (): void {
    foreach (glob(base_path('modules/27_Assistant/src/Catalogue/*Query.php')) as $file) {
        $source = (string) file_get_contents($file);

        expect($source)->not->toContain('$question')
            ->and(preg_match('/selectRaw\(\s*"[^"]*\{?\$/', $source))->toBe(0)
            ->and(preg_match('/(whereRaw|DB::raw|DB::select|DB::statement|DB::unprepared)/', $source))->toBe(0);
    }
});

test('parameters are integers inside their bounds, nothing else gets through', function (): void {
    $catalogue = app(QueryCatalogue::class);
    $query = $catalogue->find('top_customers');

    expect($catalogue->validatedParameters($query, ['year' => '2025', 'limit' => 5, 'sql' => 'DROP TABLE users']))->toBe(['year' => 2025, 'limit' => 5])
        ->and($catalogue->validatedParameters($query, ['year' => '2025; DROP TABLE users', 'limit' => 9999]))->toBe(['year' => (int) now()->year, 'limit' => 10]);
});

test('the runner makes the database refuse writes', function (): void {
    expect(fn () => app(ReadOnlyQueryRunner::class)->run(fn () => DB::table('tenants')->update(['name' => 'Hacked'])))
        ->toThrow(QueryException::class, 'read-only');

    expect(DB::table('tenants')->where('name', 'Hacked')->exists())->toBeFalse();

    DB::table('tenants')->where('id', $this->tenant->id)->update(['legal_name' => 'Still writable']);
    expect(DB::table('tenants')->where('legal_name', 'Still writable')->exists())->toBeTrue();
});

test('a question outside the role is refused and logged, and the catalogue hides it', function (): void {
    assistantActAs(assistantMember($this->tenant, 'member'), $this->tenant);

    $data = $this->postJson('/api/v1/assistant/ask', ['question' => 'Qual è la liquidità?'])->assertOk()->json('data');

    expect($data['outcome'])->toBe('forbidden')->and($data['rows'])->toBe([]);

    $keys = collect($this->getJson('/api/v1/assistant/catalogue')->assertOk()->json('data'))->pluck('key')->all();

    expect($keys)->toContain('open_receivables')->not->toContain('cash_position')->not->toContain('expiring_contracts');
    expect(AssistantQuestionLog::query()->where('outcome', 'forbidden')->count())->toBe(1);
});

test('every question is logged, whatever the outcome', function (): void {
    $this->postJson('/api/v1/assistant/ask', ['question' => 'Che tempo fa domani?'])->assertOk()->assertJsonPath('data.outcome', 'unmatched');
    $this->postJson('/api/v1/assistant/ask', ['question' => 'crediti aperti'])->assertOk();

    $logs = AssistantQuestionLog::query()->orderBy('created_at')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('outcome')->all())->toEqualCanonicalizing(['unmatched', 'answered'])
        ->and($logs->pluck('question')->all())->toContain('Che tempo fa domani?')
        ->and((string) $logs->first()->user_id)->toBe((string) $this->accountant->id);
});

test('only administrators read the log, and only of their tenant', function (): void {
    $this->postJson('/api/v1/assistant/ask', ['question' => 'crediti aperti'])->assertOk();
    $this->getJson('/api/v1/assistant/logs')->assertForbidden();

    assistantActAs(assistantMember($this->tenant, 'admin'), $this->tenant);
    $this->getJson('/api/v1/assistant/logs')->assertOk()->assertJsonCount(1, 'data');

    assistantActAs(assistantMember($this->other, 'admin'), $this->other);
    $this->getJson('/api/v1/assistant/logs')->assertOk()->assertJsonPath('data', []);
});

test('a model answering with SQL matches nothing and nothing runs', function (): void {
    TenantAiSetting::create(['tenant_id' => $this->tenant->id, 'external_providers_enabled' => true]);
    registerAssistantModel('SELECT * FROM users; DROP TABLE tenants;');

    $this->postJson('/api/v1/assistant/ask', ['question' => 'dammi tutti gli utenti'])->assertOk()
        ->assertJsonPath('data.outcome', 'unmatched')
        ->assertJsonPath('data.rows', []);

    expect(DB::table('tenants')->count())->toBeGreaterThan(0);
});

test('a model naming an unknown query or smuggling SQL as a key or parameter gets nothing', function (string $answer, string $outcome): void {
    TenantAiSetting::create(['tenant_id' => $this->tenant->id, 'external_providers_enabled' => true]);
    registerAssistantModel($answer);

    $this->postJson('/api/v1/assistant/ask', ['question' => 'x'])->assertOk()->assertJsonPath('data.outcome', $outcome);
})->with([
    ['{"query":"users; DROP TABLE tenants","parameters":{}}', 'unmatched'],
    ['{"query":"raw_sql","sql":"SELECT 1"}', 'unmatched'],
    ['{"query":"top_customers","parameters":{"limit":"1 OR 1=1","year":"2026; DROP TABLE tenants"}}', 'answered'],
]);

test('a model can pick a key, and its parameters are validated', function (): void {
    TenantAiSetting::create(['tenant_id' => $this->tenant->id, 'external_providers_enabled' => true]);
    registerAssistantModel('{"query":"revenue_by_month","parameters":{"year":2026}}');
    assistantInvoice($this->tenant, '300.00');

    $data = $this->postJson('/api/v1/assistant/ask', ['question' => 'andamento vendite'])->assertOk()->json('data');

    expect($data['query'])->toBe('revenue_by_month')
        ->and($data['parameters'])->toBe(['year' => 2026])
        ->and((float) $data['rows'][0]['revenue'])->toBe(300.0);
});

test('without consent the question never reaches an external driver', function (): void {
    $called = false;
    registerAssistantModel('{"query":"cash_position"}', $called);

    $this->postJson('/api/v1/assistant/ask', ['question' => 'crediti aperti del cliente Rossi'])->assertOk()
        ->assertJsonPath('data.query', 'open_receivables');

    expect($called)->toBeFalse()
        ->and(AssistantQuestionLog::query()->firstOrFail()->resolver)->toBe('keywords');
});
