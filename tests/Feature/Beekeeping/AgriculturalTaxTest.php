<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/BeekeepingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    beeSetUp($this);
});

function beeQuote(object $test, array $payload): array
{
    return $test->postJson(BEE_API.'/tax/quote', $payload)->assertOk()->json('data');
}

it('defaults an Italian producer to the special agricultural regime and a Swiss one to the standard regime', function (): void {
    $this->getJson(BEE_API.'/tax/profile?country=IT')->assertOk()->assertJsonPath('data.regime', 'special_agricultural')->assertJsonPath('data.compensation_defaults.honey', 8.8);
    $this->getJson(BEE_API.'/tax/profile?country=CH')->assertOk()->assertJsonPath('data.regime', 'standard');
});

it('charges the compensation percentage of honey under the Art. 34 special regime without deducting input VAT', function (): void {
    $quote = beeQuote($this, ['country' => 'IT', 'product_kind' => 'honey', 'net_amount' => 1000, 'sale_channel' => 'direct']);

    expect($quote['rate'])->toBe(8.8)->and($quote['vat'])->toBe(88.0)->and($quote['gross'])->toBe(1088.0)->and($quote['input_vat_deductible'])->toBeFalse()->and($quote['notes'])->toBe(['it_special_agricultural']);
});

it('applies the compensation overrides of the tax profile per product', function (): void {
    $this->putJson(BEE_API.'/tax/profile', ['country' => 'IT', 'regime' => 'special_agricultural', 'compensation_overrides' => ['beeswax' => 7.3]])->assertOk()->assertJsonPath('data.compensation_overrides.beeswax', 7.3);

    expect(beeQuote($this, ['country' => 'IT', 'product_kind' => 'beeswax', 'net_amount' => 200])['vat'])->toBe(14.6);
    expect(beeQuote($this, ['country' => 'IT', 'product_kind' => 'honey', 'net_amount' => 200])['rate'])->toBe(8.8);
});

it('charges no VAT under the Art. 34 comma 6 exemption and warns when the threshold is exceeded', function (): void {
    $this->putJson(BEE_API.'/tax/profile', ['country' => 'IT', 'regime' => 'exemption', 'annual_turnover' => 6500])->assertOk();

    $under = beeQuote($this, ['country' => 'IT', 'product_kind' => 'honey', 'net_amount' => 400]);
    expect($under['vat'])->toBe(0.0)->and($under['notes'])->toBe(['it_exempt_art34_c6']);

    $over = beeQuote($this, ['country' => 'IT', 'product_kind' => 'honey', 'net_amount' => 600]);
    expect($over['notes'])->toBe(['it_exempt_art34_c6', 'it_exemption_threshold_exceeded']);
});

it('uses the ordinary rates and deducts input VAT in the ordinary regime', function (string $kind, float $rate): void {
    $this->putJson(BEE_API.'/tax/profile', ['country' => 'IT', 'regime' => 'ordinary'])->assertOk();

    $quote = beeQuote($this, ['country' => 'IT', 'product_kind' => $kind, 'net_amount' => 100]);
    expect($quote['rate'])->toBe($rate)->and($quote['input_vat_deductible'])->toBeTrue();
})->with([['honey', 10.0], ['beeswax', 22.0], ['non_food', 22.0]]);

it('never applies the agricultural compensation to non food items', function (): void {
    $quote = beeQuote($this, ['country' => 'IT', 'product_kind' => 'non_food', 'net_amount' => 100]);

    expect($quote['rate'])->toBe(22.0)->and($quote['notes'])->toBe(['it_ordinary_rate']);
});

it('charges the Swiss reduced rate of 2.6% on food in direct and wholesale sales and 8.1% on the rest', function (string $kind, string $channel, float $rate, float $vat): void {
    $quote = beeQuote($this, ['country' => 'CH', 'product_kind' => $kind, 'sale_channel' => $channel, 'net_amount' => 100]);

    expect($quote['rate'])->toBe($rate)->and($quote['vat'])->toBe($vat)->and($quote['channel'])->toBe($channel);
})->with([
    'honey direct' => ['honey', 'direct', 2.6, 2.6],
    'honey wholesale' => ['honey', 'wholesale', 2.6, 2.6],
    'propolis' => ['propolis', 'direct', 2.6, 2.6],
    'beeswax candles' => ['beeswax', 'direct', 8.1, 8.1],
    'equipment' => ['non_food', 'wholesale', 8.1, 8.1],
]);

it('refuses a regime that does not exist in the country', function (): void {
    $this->putJson(BEE_API.'/tax/profile', ['country' => 'CH', 'regime' => 'exemption'])->assertUnprocessable()->assertJsonPath('code', 'tax_regime_invalid');
});

it('keeps the tax profile to owners, administrators and accountants while members can still quote', function (): void {
    treasuryActor($this, 'member');

    $this->putJson(BEE_API.'/tax/profile', ['country' => 'IT', 'regime' => 'ordinary'])->assertForbidden();
    $this->postJson(BEE_API.'/tax/quote', ['country' => 'IT', 'product_kind' => 'honey', 'net_amount' => 100])->assertOk();
    $this->getJson(BEE_API.'/tax/profile?country=IT')->assertOk();
});

it('keeps the profile of a company away from the others', function (): void {
    $this->putJson(BEE_API.'/tax/profile', ['country' => 'IT', 'regime' => 'ordinary'])->assertOk();

    treasuryActor($this, 'accountant', $this->otherTenant);
    $this->getJson(BEE_API.'/tax/profile?country=IT')->assertOk()->assertJsonPath('data.regime', 'special_agricultural');
});
