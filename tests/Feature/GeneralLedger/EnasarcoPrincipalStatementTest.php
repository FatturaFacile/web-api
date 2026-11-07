<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\PostPassiveInvoiceAction;
use Modules\GeneralLedger\Models\EnasarcoParameter;
use Modules\GeneralLedger\Models\EnasarcoPrincipalStatement;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\EnasarcoPrincipalService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Mandante', 'legal_name' => 'Mandante Srl', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->user = User::create(['name' => 'Ada', 'email' => Str::random(8).'@example.com', 'password' => bcrypt('secret123')]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    $this->agent = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'supplier', 'company_name' => 'Agente Uno', 'country' => 'IT', 'vat_number' => '01234567897']);
    $this->otherAgent = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'supplier', 'company_name' => 'Agente Due', 'country' => 'IT', 'vat_number' => '09876543210']);

    app(TenantContext::class)->setTenant($this->tenant);
    fiscalCalendar($this->tenant);

    // Figures of the test only: the application seeds no Enasarco parameter.
    EnasarcoParameter::create(['tenant_id' => $this->tenant->id, 'year' => 2027, 'agent_type' => 'multi_mandate', 'rate_percent' => 10, 'agent_share_percent' => 50, 'annual_ceiling' => 1500, 'source' => 'Test figures']);
    EnasarcoParameter::create(['tenant_id' => $this->tenant->id, 'year' => 2027, 'agent_type' => 'single_mandate', 'rate_percent' => 10, 'agent_share_percent' => 50, 'annual_ceiling' => 3000, 'source' => 'Test figures']);
});

function agentCommission(Contact $agent, string $date, float $net, float $withheld, string $type = 'TD01'): PassiveInvoice
{
    return app(PostPassiveInvoiceAction::class)->register([
        'tenant_id' => test()->tenant->id,
        'supplier_id' => $agent->id,
        'invoice_number' => 'PROV-'.Str::random(5),
        'date' => $date,
        'total_amount' => $net,
        'total_net' => $net,
        'total_tax' => 0,
        'currency' => 'EUR',
        'document_type' => $type,
        'payment_status' => 'unpaid',
        'withholdings' => [['type' => 'RT04', 'amount' => number_format($withheld, 2, '.', ''), 'rate' => '5.00', 'reason' => 'R']],
        'withholding_tax_amount' => $withheld,
    ]);
}

function principalShareBalance(string $code): float
{
    return round((float) DB::table('journal_lines')
        ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
        ->where('chart_of_accounts.tenant_id', test()->tenant->id)
        ->where('chart_of_accounts.account_code', $code)
        ->selectRaw('COALESCE(SUM(journal_lines.debit_amount - journal_lines.credit_amount), 0) AS balance')
        ->value('balance'), 2);
}

test('the quarterly statement adds the principal share to what the agents had withheld, within the annual ceiling', function (): void {
    agentCommission($this->agent, '2027-02-10', 1000, 50);
    agentCommission($this->agent, '2027-05-10', 1000, 25);
    agentCommission($this->otherAgent, '2027-04-20', 400, 20);

    Sanctum::actingAs($this->user);
    $headers = ['X-Tenant-ID' => $this->tenant->id];

    $this->withHeaders($headers)->postJson('/api/v1/general-ledger/enasarco/principal-statements', ['year' => 2027, 'quarter' => 1, 'agent_type' => 'multi_mandate'])
        ->assertOk()->assertJsonPath('data.total_principal_amount', '50.00')->assertJsonPath('data.total_due', '100.00');

    $response = $this->withHeaders($headers)->postJson('/api/v1/general-ledger/enasarco/principal-statements', [
        'year' => 2027, 'quarter' => 2, 'agent_type' => 'multi_mandate', 'agent_types' => [$this->otherAgent->id => 'single_mandate'],
    ])->assertOk();

    $lines = collect($response->json('data.lines'))->keyBy('contact_id');

    expect($lines[$this->agent->id])->toMatchArray(['commissions' => '1000.00', 'subject_before' => '1000.00', 'subject_base' => '500.00', 'principal_amount' => '25.00', 'agent_withheld' => '25.00', 'total_due' => '50.00'])
        ->and($lines[$this->otherAgent->id])->toMatchArray(['agent_type' => 'single_mandate', 'subject_base' => '400.00', 'principal_amount' => '20.00'])
        ->and($response->json('data.total_due'))->toBe('90.00')
        ->and(principalShareBalance('6475'))->toBe(95.0)
        ->and(principalShareBalance('2235'))->toBe(-190.0);

    $this->withHeaders($headers)->getJson('/api/v1/general-ledger/enasarco/principal-statements?year=2027&quarter=2')
        ->assertOk()->assertJsonCount(2, 'data.lines')->assertJsonPath('data.total_principal_amount', '45.00');
});

test('a second run posts nothing twice and a missing parameter refuses the whole quarter', function (): void {
    agentCommission($this->agent, '2027-02-10', 1000, 50);
    $service = app(EnasarcoPrincipalService::class);

    $service->generate($this->tenant->id, 2027, 1, 'multi_mandate');
    $service->generate($this->tenant->id, 2027, 1, 'multi_mandate');

    expect(EnasarcoPrincipalStatement::query()->count())->toBe(1)
        ->and(JournalEntry::query()->where('source_document_type', EnasarcoPrincipalService::Source)->count())->toBe(1);

    EnasarcoParameter::query()->delete();
    agentCommission($this->otherAgent, '2027-04-20', 400, 20);

    expect(fn () => $service->generate($this->tenant->id, 2027, 2, 'multi_mandate'))->toThrow(ValidationException::class)
        ->and(EnasarcoPrincipalStatement::query()->where('quarter', 2)->count())->toBe(0);
});

test('an agent credit note takes back commissions and withholding of its quarter', function (): void {
    agentCommission($this->agent, '2027-02-10', 1000, 50);
    agentCommission($this->agent, '2027-03-10', 200, 10, 'TD04');

    $line = app(EnasarcoPrincipalService::class)->generate($this->tenant->id, 2027, 1, 'multi_mandate')->sole();

    expect((string) $line->commissions)->toBe('800.00')
        ->and((string) $line->agent_withheld)->toBe('40.00')
        ->and((string) $line->principal_amount)->toBe('40.00');
});

test('generating needs the accounting rights', function (): void {
    $member = User::create(['name' => 'Bob', 'email' => Str::random(8).'@example.com', 'password' => bcrypt('secret123')]);
    $member->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    Sanctum::actingAs($member);

    $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])->postJson('/api/v1/general-ledger/enasarco/principal-statements', ['year' => 2027, 'quarter' => 1, 'agent_type' => 'multi_mandate'])->assertForbidden();
});
