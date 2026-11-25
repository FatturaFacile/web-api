<?php

declare(strict_types=1);

use App\Models\BankStatementLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Treasury\Exceptions\OpenBankingUnavailableException;
use Modules\Treasury\Gateways\BlinkOpenBankingGateway;
use Modules\Treasury\Gateways\Psd2OpenBankingGateway;
use Modules\Treasury\Gateways\SandboxOpenBankingGateway;
use Modules\Treasury\Models\OpenBankingSyncRun;
use Modules\Treasury\Services\OpenBankingService;

require_once __DIR__.'/TreasurySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    treasurySetUp($this);
    config(['open_banking.driver' => 'sandbox']);
});

function obConsent(object $test, string $iban = 'IT60X0542811101000000123456'): string
{
    return $test->postJson('/api/v1/treasury/open-banking/consents', ['iban' => $iban])->assertCreated()->json('data.id');
}

test('the status endpoint names the driver and whether it can be used', function (): void {
    $this->getJson('/api/v1/treasury/open-banking/status')->assertOk()->assertJsonPath('data.driver', 'sandbox')->assertJsonPath('data.available', true);

    config(['open_banking.driver' => 'refused']);
    $this->getJson('/api/v1/treasury/open-banking/status')->assertOk()->assertJsonPath('data.driver', 'refused')->assertJsonPath('data.available', false);
});

test('a sync imports the movements of the provider as a bank statement', function (): void {
    $consent = obConsent($this);
    $from = now()->subDays(2)->toDateString();
    $to = now()->toDateString();

    $run = $this->postJson("/api/v1/treasury/open-banking/consents/{$consent}/sync", ['from' => $from, 'to' => $to, 'auto_reconcile' => false])->assertOk()->json('data');

    expect($run['status'])->toBe('completed')
        ->and($run['fetched'])->toBe(9)
        ->and($run['imported'])->toBe(9)
        ->and($run['duplicates'])->toBe(0)
        ->and($run['bank_statement_id'])->not->toBeNull()
        ->and(BankStatementLine::query()->where('bank_statement_id', $run['bank_statement_id'])->count())->toBe(9)
        ->and(BankStatementLine::query()->where('bank_statement_id', $run['bank_statement_id'])->where('direction', 'debit')->count())->toBe(3);
});

test('syncing the same or an overlapping period never duplicates a movement', function (): void {
    $consent = obConsent($this);
    $range = ['from' => now()->subDays(2)->toDateString(), 'to' => now()->toDateString(), 'auto_reconcile' => false];

    $this->postJson("/api/v1/treasury/open-banking/consents/{$consent}/sync", $range)->assertOk();
    $again = $this->postJson("/api/v1/treasury/open-banking/consents/{$consent}/sync", $range)->assertOk()->json('data');
    $overlap = $this->postJson("/api/v1/treasury/open-banking/consents/{$consent}/sync", ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString(), 'auto_reconcile' => false])->assertOk()->json('data');

    expect($again['imported'])->toBe(0)->and($again['duplicates'])->toBe(9)
        ->and($overlap['imported'])->toBe(0)->and($overlap['duplicates'])->toBe(6)->and($overlap['bank_statement_id'])->toBeNull()
        ->and(BankStatementLine::query()->count())->toBe(9)
        ->and(OpenBankingSyncRun::query()->count())->toBe(3);
});

test('the Swiss sandbox account is in CHF', function (): void {
    $consent = obConsent($this, 'CH9300762011623852957');

    $this->postJson("/api/v1/treasury/open-banking/consents/{$consent}/sync", ['from' => now()->toDateString(), 'to' => now()->toDateString(), 'auto_reconcile' => false])->assertOk();

    expect(BankStatementLine::query()->first()->currency)->toBe('CHF');
});

test('a failing provider is answered with 503 and the failed run is kept', function (): void {
    $consent = obConsent($this);
    config(['open_banking.driver' => 'psd2', 'open_banking.psd2.base_url' => null]);

    $this->postJson("/api/v1/treasury/open-banking/consents/{$consent}/sync", [])->assertStatus(503);

    $run = OpenBankingSyncRun::query()->first();

    expect($run->status)->toBe('failed')->and($run->error)->not->toBeNull();
    $this->getJson('/api/v1/treasury/open-banking/sync-runs')->assertOk()->assertJsonPath('data.0.status', 'failed');
});

test('connections list their last sync and an expired consent cannot be synchronised', function (): void {
    $consent = obConsent($this);
    $this->postJson("/api/v1/treasury/open-banking/consents/{$consent}/sync", ['auto_reconcile' => false])->assertOk();

    $this->getJson('/api/v1/treasury/open-banking/consents')->assertOk()
        ->assertJsonPath('data.0.iban', 'IT60X0542811101000000123456')
        ->assertJsonPath('data.0.is_active', true)
        ->assertJsonPath('data.0.last_sync.status', 'completed');

    DB::table('open_banking_consents')->where('id', $consent)->update(['expires_at' => now()->subDay()]);
    $this->postJson("/api/v1/treasury/open-banking/consents/{$consent}/sync", [])->assertStatus(409);
});

test('the sync command imports every active consent of every company', function (): void {
    obConsent($this);
    $this->artisan('treasury:open-banking-sync', ['--from' => now()->toDateString(), '--to' => now()->toDateString()])->assertSuccessful();

    expect(BankStatementLine::withoutGlobalScopes()->count())->toBe(3);
});

test('the sync is refused for another company and the dates cannot be in the future', function (): void {
    $consent = obConsent($this);
    $this->postJson("/api/v1/treasury/open-banking/consents/{$consent}/sync", ['to' => now()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors(['to']);

    treasuryActor($this, 'accountant', $this->otherTenant);
    $this->postJson("/api/v1/treasury/open-banking/consents/{$consent}/sync", [])->assertNotFound();
});

test('the PSD2 gateway reads balances and booked transactions of a NextGenPSD2 provider', function (): void {
    config(['open_banking.psd2' => array_merge(config('open_banking.psd2'), ['base_url' => 'https://bank.test', 'client_id' => 'id', 'client_secret' => 'secret', 'token_url' => null])]);
    Http::fake([
        'bank.test/v1/accounts' => Http::response(['accounts' => [['iban' => 'IT60X0542811101000000123456', 'resourceId' => 'acc-1']]]),
        'bank.test/v1/accounts/acc-1/balances' => Http::response(['balances' => [['balanceType' => 'closingBooked', 'balanceAmount' => ['currency' => 'EUR', 'amount' => '1500.5']]]]),
        'bank.test/v1/accounts/acc-1/transactions*' => Http::response(['transactions' => ['booked' => [
            ['transactionId' => 'T1', 'bookingDate' => '2026-03-01', 'transactionAmount' => ['currency' => 'EUR', 'amount' => '-95.00'], 'creditorName' => 'Fornitore', 'creditorAccount' => ['iban' => 'DE89370400440532013000'], 'remittanceInformationUnstructured' => 'Invoice 7', 'endToEndId' => 'E2E-7'],
            ['entryReference' => 'T2', 'bookingDate' => '2026-03-02', 'transactionAmount' => ['currency' => 'EUR', 'amount' => '250.00'], 'debtorName' => 'Cliente', 'remittanceInformationUnstructured' => 'Saldo'],
        ]]]),
    ]);
    $gateway = new Psd2OpenBankingGateway;

    expect($gateway->isAvailable())->toBeTrue()
        ->and($gateway->accountBalance('consent-1', 'IT60X0542811101000000123456'))->toBe(['iban' => 'IT60X0542811101000000123456', 'currency' => 'EUR', 'balance' => '1500.50']);

    $rows = $gateway->transactions('consent-1', 'IT60X0542811101000000123456', '2026-03-01', '2026-03-02');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['external_id'])->toBe('T1')
        ->and($rows[0]['amount'])->toBe('-95.00')
        ->and($rows[0]['counterpart_name'])->toBe('Fornitore')
        ->and($rows[0]['counterpart_iban'])->toBe('DE89370400440532013000')
        ->and($rows[1]['external_id'])->toBe('T2')
        ->and($rows[1]['counterpart_name'])->toBe('Cliente');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Consent-ID', 'consent-1') && $request->hasHeader('X-Request-ID'));
});

test('the bLink gateway maps the credit / debit indicator and the structured reference', function (): void {
    config(['open_banking.blink' => array_merge(config('open_banking.blink'), ['base_url' => 'https://blink.test', 'client_id' => 'id', 'client_secret' => 'secret', 'token_url' => null])]);
    Http::fake([
        'blink.test/accounts' => Http::response(['accounts' => [['iban' => 'CH9300762011623852957', 'id' => 'ch-1']]]),
        'blink.test/accounts/ch-1/transactions*' => Http::response(['transactions' => [
            ['id' => 'B1', 'bookingDate' => '2026-03-01', 'amount' => ['value' => '1949.75', 'currency' => 'CHF'], 'creditDebitIndicator' => 'CRDT', 'structuredReference' => '210000000003139471430009017', 'counterparty' => ['name' => 'Robert Schneider AG']],
            ['id' => 'B2', 'bookingDate' => '2026-03-01', 'amount' => ['value' => '20.00', 'currency' => 'CHF'], 'creditDebitIndicator' => 'DBIT', 'remittanceInformation' => 'Fee'],
        ]]),
    ]);

    $rows = (new BlinkOpenBankingGateway)->transactions('consent', 'CH9300762011623852957', '2026-03-01', '2026-03-01');

    expect($rows[0]['amount'])->toBe('1949.75')->and($rows[0]['reference'])->toBe('210000000003139471430009017')->and($rows[0]['counterpart_name'])->toBe('Robert Schneider AG')
        ->and($rows[1]['amount'])->toBe('-20.00')->and($rows[1]['currency'])->toBe('CHF');
});

test('provider failures become an unavailable exception instead of an empty result', function (): void {
    config(['open_banking.psd2' => array_merge(config('open_banking.psd2'), ['base_url' => 'https://bank.test', 'client_id' => 'id', 'client_secret' => 'secret', 'token_url' => null])]);
    Http::fake(['bank.test/*' => Http::response(['error' => 'down'], 500)]);

    expect(fn () => (new Psd2OpenBankingGateway)->transactions('consent', 'IT60X0542811101000000123456', '2026-03-01', '2026-03-02'))->toThrow(OpenBankingUnavailableException::class);

    config(['open_banking.psd2.base_url' => null]);

    expect((new Psd2OpenBankingGateway)->isAvailable())->toBeFalse()
        ->and(fn () => (new Psd2OpenBankingGateway)->requestConsent('IT60X0542811101000000123456'))->toThrow(OpenBankingUnavailableException::class);
});

test('the drivers are selected by configuration and the sandbox never runs in production', function (): void {
    foreach (['sandbox' => 'sandbox', 'psd2' => 'psd2', 'blink' => 'blink', 'local' => 'local', 'refused' => 'refused', 'unknown' => 'refused'] as $driver => $name) {
        config(['open_banking.driver' => $driver]);

        expect(app(OpenBankingService::class)->gateway()->name())->toBe($name);
    }

    $this->app['env'] = 'production';

    expect((new SandboxOpenBankingGateway)->isAvailable())->toBeFalse();
});
