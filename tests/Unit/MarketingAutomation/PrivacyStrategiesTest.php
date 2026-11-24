<?php

declare(strict_types=1);

use Modules\MarketingAutomation\Domain\ValueObjects\RecipientContext;
use Modules\MarketingAutomation\Models\MarketingConsent;
use Modules\MarketingAutomation\Services\Privacy\ChLcslArt3OptInOutNlpdStrategy;
use Modules\MarketingAutomation\Services\Privacy\ItGdprArt130CodicePrivacyConsensiStrategy;
use Modules\MarketingAutomation\Services\Privacy\PrivacyStrategyResolver;

function maContext(string $jurisdiction, bool $customer = false, bool $similar = false, bool $suppressed = false, bool $identified = true): RecipientContext
{
    return new RecipientContext($jurisdiction, 'it', $customer, $similar, $identified, $suppressed);
}

test('CH LCSl: prior consent is required unless an existing customer gets similar products with a soft opt-in', function (): void {
    $strategy = new ChLcslArt3OptInOutNlpdStrategy;

    expect($strategy->evaluate(null, maContext('CH'), 'email')->allowed)->toBeFalse()
        ->and($strategy->evaluate(new MarketingConsent(['marketing_opt_in' => true]), maContext('CH'), 'email')->lawfulBasis)->toBe('consent')
        ->and($strategy->evaluate(new MarketingConsent(['soft_opt_in_eligible' => true]), maContext('CH', true, true), 'email')->lawfulBasis)->toBe('soft_opt_in')
        ->and($strategy->evaluate(new MarketingConsent(['soft_opt_in_eligible' => true]), maContext('CH', true, false), 'email')->allowed)->toBeFalse()
        ->and($strategy->evaluate(new MarketingConsent(['soft_opt_in_eligible' => true]), maContext('CH', false, true), 'email')->allowed)->toBeFalse();
});

test('CH LCSl: unsubscribed, suppressed and anonymous senders are always refused', function (): void {
    $strategy = new ChLcslArt3OptInOutNlpdStrategy;
    $optedIn = new MarketingConsent(['marketing_opt_in' => true]);
    $unsubscribed = new MarketingConsent(['marketing_opt_in' => true, 'unsubscribed_at' => now()]);

    expect($strategy->evaluate($unsubscribed, maContext('CH'), 'email')->reasonCode)->toBe('unsubscribed')
        ->and($strategy->evaluate($optedIn, maContext('CH', suppressed: true), 'email')->reasonCode)->toBe('suppressed')
        ->and($strategy->evaluate($optedIn, maContext('CH', identified: false), 'email')->reasonCode)->toBe('sender_not_identified')
        ->and($strategy->evaluate(new MarketingConsent(['marketing_opt_in' => true, 'channel_preferences' => ['whatsapp' => false]]), maContext('CH'), 'whatsapp')->reasonCode)->toBe('channel_opt_out');
});

test('IT Art. 130: direct marketing consent, soft spam for e-mail only, and profiling consent are distinct', function (): void {
    $strategy = new ItGdprArt130CodicePrivacyConsensiStrategy;
    $soft = new MarketingConsent(['soft_opt_in_eligible' => true]);

    expect($strategy->evaluate(new MarketingConsent(['marketing_opt_in' => true]), maContext('IT'), 'email')->lawfulBasis)->toBe('consent_art130_c1')
        ->and($strategy->evaluate($soft, maContext('IT', true, true), 'email')->lawfulBasis)->toBe('soft_opt_in_art130_c4')
        ->and($strategy->evaluate($soft, maContext('IT', true, true), 'whatsapp')->allowed)->toBeFalse()
        ->and($strategy->evaluate(null, maContext('IT', true, true), 'email')->allowed)->toBeFalse()
        ->and($strategy->allowsProfiling(new MarketingConsent(['marketing_opt_in' => true])))->toBeFalse()
        ->and($strategy->allowsProfiling(new MarketingConsent(['profiling_opt_in' => true])))->toBeTrue()
        ->and($strategy->allowsProfiling(new MarketingConsent(['profiling_opt_in' => true, 'unsubscribed_at' => now()])))->toBeFalse();
});

test('both strategies emit RFC 8058 one-click unsubscribe headers', function (): void {
    foreach ([new ChLcslArt3OptInOutNlpdStrategy, new ItGdprArt130CodicePrivacyConsensiStrategy] as $strategy) {
        $headers = $strategy->unsubscribeHeaders('https://api.example.ch/u/token', 'unsubscribe@example.ch');

        expect($headers['List-Unsubscribe'])->toBe('<https://api.example.ch/u/token>, <mailto:unsubscribe@example.ch>')
            ->and($headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
            ->and($strategy->unsubscribeHeaders('https://api.example.ch/u/token', null)['List-Unsubscribe'])->toBe('<https://api.example.ch/u/token>');
    }
});

test('the resolver picks LCSl for Swiss recipients and GDPR for everybody else', function (): void {
    $resolver = new PrivacyStrategyResolver(new ChLcslArt3OptInOutNlpdStrategy, new ItGdprArt130CodicePrivacyConsensiStrategy);

    expect($resolver->forJurisdiction('CH')->code())->toBe(ChLcslArt3OptInOutNlpdStrategy::CODE)
        ->and($resolver->forJurisdiction('ch')->code())->toBe(ChLcslArt3OptInOutNlpdStrategy::CODE)
        ->and($resolver->forJurisdiction('IT')->code())->toBe(ItGdprArt130CodicePrivacyConsensiStrategy::CODE)
        ->and($resolver->forJurisdiction('DE')->code())->toBe(ItGdprArt130CodicePrivacyConsensiStrategy::CODE)
        ->and($resolver->forJurisdiction(null, 'CH')->code())->toBe(ChLcslArt3OptInOutNlpdStrategy::CODE)
        ->and($resolver->forJurisdiction('', 'IT')->code())->toBe(ItGdprArt130CodicePrivacyConsensiStrategy::CODE);
});
