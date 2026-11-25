<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\TenderManagement\Contracts\CompanyCapabilityProviderInterface;
use Modules\TenderManagement\Domain\ValueObjects\CompanyProfile;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Services\EligibilityEvaluator;
use Modules\TenderManagement\Strategies\TenderStrategyRegistry;

require_once __DIR__.'/TenderUnitSupport.php';

function tndEvaluator(string $turnover, int $projects): EligibilityEvaluator
{
    $provider = new class($turnover, $projects) implements CompanyCapabilityProviderInterface
    {
        public function __construct(private readonly string $turnover, private readonly int $projects) {}

        public function profileFor(TenderNotice $notice, CarbonImmutable $today): CompanyProfile
        {
            return new CompanyProfile($this->turnover, $this->projects, '0.00');
        }
    };

    return new EligibilityEvaluator(app(TenderStrategyRegistry::class), $provider);
}

/**
 * @return array<string, mixed>
 */
function tndRequirements(): array
{
    return [
        'required_min_turnover' => '1800000.00',
        'required_similar_projects_count' => 2,
        'required_soa_categories' => [['category' => 'OG1', 'classification' => 'III']],
        'required_certifications' => ['IT_ISO9001'],
    ];
}

function tndCompleteVault(): Collection
{
    return tndVault([
        tndDoc('IT_SOA_Attestation', ['soa_categories_classifications' => [['category' => 'OG1', 'classification' => 'IV']], 'fvoe20_synced' => true]),
        tndDoc('IT_DURC', ['fvoe20_synced' => true]),
        tndDoc('IT_ISO9001'),
    ]);
}

it('says go and recommends bidding alone when the company meets every requirement', function (): void {
    $result = tndEvaluator('2500000.00', 3)->evaluate(tndNotice(tndRequirements()), tndCompleteVault(), tndToday());

    expect($result['decision'])->toBe('go')
        ->and($result['can_bid_solo'])->toBeTrue()
        ->and($result['recommended_mode'])->toBe('SingleCompany')
        ->and($result['overall_score'])->toBe(100)
        ->and(array_column($result['criteria'], 'met'))->each->toBeTrue()
        ->and(array_column($result['issues'], 'code'))->toBe(['solo_eligible']);
});

it('recommends a temporary grouping with the exact quota when the turnover falls short but the company holds the majority', function (): void {
    $result = tndEvaluator('900000.00', 2)->evaluate(tndNotice(tndRequirements()), tndCompleteVault(), tndToday());

    expect($result['can_bid_solo'])->toBeFalse()
        ->and($result['recommended_mode'])->toBe('RTI_Consorzio_Mandataria_Mandante')
        ->and($result['gaps']['turnover_gap'])->toBe('900000.00')
        ->and($result['gaps']['rti_quota_percentage_suggested'])->toBe('50.00')
        ->and($result['decision'])->toBe('conditional')
        ->and(array_column($result['issues'], 'code'))->toContain('turnover_gap')->toContain('rti_recommended');
});

it('recommends the avvalimento when the company covers too little of the quantitative requirements', function (): void {
    $result = tndEvaluator('300000.00', 0)->evaluate(tndNotice(tndRequirements()), tndCompleteVault(), tndToday());

    expect($result['recommended_mode'])->toBe('Avvalimento')
        ->and($result['gaps']['rti_quota_percentage_suggested'])->toBeNull()
        ->and(array_column($result['issues'], 'code'))->toContain('avvalimento_recommended');
});

it('reports the SOA classification gap and the missing certification', function (): void {
    $vault = tndVault([
        tndDoc('IT_SOA_Attestation', ['soa_categories_classifications' => [['category' => 'OG1', 'classification' => 'II']], 'fvoe20_synced' => true]),
        tndDoc('IT_DURC', ['fvoe20_synced' => true]),
    ]);
    $result = tndEvaluator('2500000.00', 3)->evaluate(tndNotice(tndRequirements()), $vault, tndToday());

    expect($result['can_bid_solo'])->toBeFalse()
        ->and($result['gaps']['soa'])->toBe([['category' => 'OG1', 'required_classification' => 'III', 'available_classification' => 'II', 'met' => false]])
        ->and($result['gaps']['missing_certifications'])->toBe(['IT_ISO9001'])
        ->and($result['recommended_mode'])->toBe('Avvalimento');
});

it('says no-go when almost nothing matches', function (): void {
    $result = tndEvaluator('0.00', 0)->evaluate(tndNotice(tndRequirements()), tndVault([]), tndToday());

    expect($result['decision'])->toBe('no_go')->and($result['overall_score'])->toBeLessThan(50);
});

it('evaluates a Swiss tender against the Swiss proofs of the vault', function (): void {
    $notice = tndNotice(['jurisdiction' => 'CH_SIMAP_LAPub', 'currency' => 'CHF', 'award_criterion' => 'LowestPrice', 'formula_type' => null]);
    $vault = tndVault([
        tndDoc('CH_EstrattoEsecuzioniFallimenti_Betreibungsauszug', ['issue_date' => tndToday()->subDays(20)->toDateString(), 'expiry_date' => null]),
        tndDoc('CH_AttestatoAVS_AI_IPG'), tndDoc('CH_AttestatoSUVA'), tndDoc('CH_AttestatoLPP'),
    ]);

    $result = tndEvaluator('0.00', 0)->evaluate($notice, $vault, tndToday());
    $missing = array_values(array_map(static fn (array $row): string => $row['key'], array_filter($result['criteria'], static fn (array $row): bool => ! $row['met'])));

    expect($result['strategy'])->toBe('CH')->and($missing)->toBe(['document:CH_AttestatoAFC_IVA']);
});
