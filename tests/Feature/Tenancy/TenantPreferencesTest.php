<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/**
 * The per-tenant preferences: whitelisted groups with typed rules, merged on update (null removes, nested options merge
 * one by one, IBAN keys are normalised), stored in the settings of the active tenant and never in another one.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA', 'country' => 'CH', 'default_currency' => 'CHF', 'settings' => ['street' => 'Via Roma 1']]);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->preferences = app(TenantPreferences::class);
});

/**
 * @param  array<string, mixed>  $values
 */
function preferenceErrors(string $group, array $values): array
{
    return Validator::make($values, app(TenantPreferences::class)->rules($group))->errors()->keys();
}

test('every group is listed, empty until something is stored', function (): void {
    $all = $this->preferences->all();

    expect(array_keys($all))->toBe(TenantPreferences::GROUPS)
        ->and(array_filter($all))->toBe([])
        ->and($this->preferences->get('invoice', 'footer_text', 'none'))->toBe('none');
});

test('an update is merged into the group, null removes a preference, the rest of the settings stays', function (): void {
    $this->preferences->update('invoice', ['footer_text' => 'Thank you', 'reminder_grace_days' => 5]);
    $result = $this->preferences->update('invoice', ['reminder_grace_days' => null, 'reminder_max_level' => 2]);

    $stored = Tenant::query()->findOrFail($this->tenant->id)->settings;

    expect($result)->toBe(['footer_text' => 'Thank you', 'reminder_max_level' => 2])
        ->and($stored['preferences']['invoice'])->toBe(['footer_text' => 'Thank you', 'reminder_max_level' => 2])
        ->and($stored['street'])->toBe('Via Roma 1')
        ->and($this->preferences->get('invoice', 'reminder_max_level'))->toBe(2);
});

test('nested options are merged one by one and an emptied group of options disappears', function (): void {
    $this->preferences->update('banking', ['reconciliation' => ['auto_match_tolerance_amount' => 1, 'allow_partial_reconciliation' => true]]);
    $merged = $this->preferences->update('banking', ['reconciliation' => ['auto_match_tolerance_amount' => 2.5]]);

    expect($merged['reconciliation'])->toBe(['auto_match_tolerance_amount' => 2.5, 'allow_partial_reconciliation' => true]);

    $removedOne = $this->preferences->update('banking', ['reconciliation' => ['allow_partial_reconciliation' => null]]);
    expect($removedOne['reconciliation'])->toBe(['auto_match_tolerance_amount' => 2.5]);

    $removedAll = $this->preferences->update('banking', ['reconciliation' => ['auto_match_tolerance_amount' => null]]);
    expect($removedAll)->toBe([]);
});

test('the bank accounts of the accounting group are stored by normalised IBAN', function (): void {
    $result = $this->preferences->update('accounting', ['bank_accounts' => ['it60 x054 2811 1010 0000 0123 456' => '1001']]);

    expect($result['bank_accounts'])->toBe(['IT60X0542811101000000123456' => '1001'])
        ->and(TenantPreferences::normalizeIban(' ch93 0076 2011 6238 5295 7 '))->toBe('CH9300762011623852957');

    $merged = $this->preferences->update('accounting', ['bank_accounts' => ['CH9300762011623852957' => '1002']]);

    expect($merged['bank_accounts'])->toBe(['IT60X0542811101000000123456' => '1001', 'CH9300762011623852957' => '1002']);
});

test('preferences of a tenant never show in another one', function (): void {
    $this->preferences->update('sdi', ['send_enabled' => true]);

    app(TenantContext::class)->setTenant($this->other);

    expect($this->preferences->get('sdi', 'send_enabled', 'unset'))->toBe('unset')
        ->and(Tenant::query()->findOrFail($this->other->id)->settings)->toBeNull();
});

test('the rules accept the documented values and refuse the others', function (string $group, array $values, array $invalidKeys): void {
    expect(preferenceErrors($group, $values))->toBe($invalidKeys);
})->with([
    'invoice reminder level out of range' => ['invoice', ['reminder_max_level' => 4, 'reminder_interval_days' => 0, 'reminder_grace_days' => 10], ['reminder_interval_days', 'reminder_max_level']],
    'invoice values at the limits' => ['invoice', ['reminder_max_level' => 3, 'reminder_interval_days' => 1, 'reminder_grace_days' => 365], []],
    'email addresses' => ['email', ['reply_to' => 'not-an-email', 'bcc' => 'a@example.test'], ['reply_to']],
    'company country is two capital letters' => ['company', ['country' => 'ch', 'timezone' => 'Europe/Zurich'], ['country']],
    'company time zone must exist' => ['company', ['timezone' => 'Mars/Base'], ['timezone']],
    'accounting codes are short and alphanumeric' => ['accounting', ['accounts' => ['revenue_account_code' => '4000.10', 'cost_account_code' => 'bad code!']], ['accounts.cost_account_code']],
    'accounting bank accounts need a valid IBAN' => ['accounting', ['bank_accounts' => ['not an iban' => '1001']], ['bank_accounts']],
    'banking tolerance is bounded' => ['banking', ['reconciliation' => ['auto_match_tolerance_amount' => 101, 'max_fee_deduction_amount' => 10000]], ['reconciliation.auto_match_tolerance_amount']],
    'sales overdue days are bounded' => ['sales', ['credit_check_mode' => 'nonsense', 'credit_block_overdue_days' => 0], ['credit_check_mode', 'credit_block_overdue_days']],
    'sales valid values' => ['sales', ['credit_block_overdue_days' => 3650], []],
    'a group without rules accepts nothing to check' => ['unknown', ['anything' => 'x'], []],
]);
