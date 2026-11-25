<?php

declare(strict_types=1);

use Modules\CustomerSatisfaction\Domain\Enums\RecoveryStatus;
use Modules\CustomerSatisfaction\Domain\Enums\ResponseBand;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyType;
use Modules\CustomerSatisfaction\Domain\Support\KpiCalculator;
use Modules\CustomerSatisfaction\Domain\Support\SurveyLocale;
use Modules\CustomerSatisfaction\Services\LexiconSentimentAnalyzer;

it('has the scale of each instrument: NPS 0-10, CSAT 1-5, CES 1-7', function (): void {
    expect([SurveyType::Nps->min(), SurveyType::Nps->max()])->toBe([0, 10])
        ->and([SurveyType::Csat->min(), SurveyType::Csat->max()])->toBe([1, 5])
        ->and([SurveyType::Ces->min(), SurveyType::Ces->max()])->toBe([1, 7])
        ->and(SurveyType::Nps->isValidScore(11))->toBeFalse()
        ->and(SurveyType::Nps->isValidScore(0))->toBeTrue()
        ->and(SurveyType::Csat->isValidScore(0))->toBeFalse()
        ->and(SurveyType::Ces->isValidScore(7))->toBeTrue();
});

it('bands NPS scores: promoters 9-10, passives 7-8, detractors 0-6', function (): void {
    foreach ([10, 9] as $score) {
        expect(SurveyType::Nps->band($score))->toBe(ResponseBand::Promoter);
    }
    foreach ([8, 7] as $score) {
        expect(SurveyType::Nps->band($score))->toBe(ResponseBand::Passive);
    }
    foreach ([6, 3, 0] as $score) {
        expect(SurveyType::Nps->band($score))->toBe(ResponseBand::Detractor);
    }
});

it('bands CSAT and CES scores', function (): void {
    expect(SurveyType::Csat->band(5))->toBe(ResponseBand::Satisfied)
        ->and(SurveyType::Csat->band(4))->toBe(ResponseBand::Satisfied)
        ->and(SurveyType::Csat->band(3))->toBe(ResponseBand::Neutral)
        ->and(SurveyType::Csat->band(2))->toBe(ResponseBand::Dissatisfied)
        ->and(SurveyType::Ces->band(1))->toBe(ResponseBand::Satisfied)
        ->and(SurveyType::Ces->band(3))->toBe(ResponseBand::Satisfied)
        ->and(SurveyType::Ces->band(4))->toBe(ResponseBand::Neutral)
        ->and(SurveyType::Ces->band(5))->toBe(ResponseBand::Dissatisfied);
});

it('detects detractors: NPS <= 6, CSAT <= 2, CES >= 5, or a custom threshold', function (): void {
    expect(SurveyType::Nps->isDetractor(6))->toBeTrue()
        ->and(SurveyType::Nps->isDetractor(7))->toBeFalse()
        ->and(SurveyType::Csat->isDetractor(2))->toBeTrue()
        ->and(SurveyType::Csat->isDetractor(3))->toBeFalse()
        ->and(SurveyType::Ces->isDetractor(5))->toBeTrue()
        ->and(SurveyType::Ces->isDetractor(4))->toBeFalse()
        ->and(SurveyType::Nps->isDetractor(8, 8))->toBeTrue()
        ->and(SurveyType::Ces->isDetractor(6, 7))->toBeFalse();
});

it('maps a band to the follow-up question of the good, the middle or the bad answers', function (): void {
    expect(ResponseBand::Promoter->promptKey())->toBe('high')
        ->and(ResponseBand::Satisfied->promptKey())->toBe('high')
        ->and(ResponseBand::Passive->promptKey())->toBe('mid')
        ->and(ResponseBand::Neutral->promptKey())->toBe('mid')
        ->and(ResponseBand::Detractor->promptKey())->toBe('low')
        ->and(ResponseBand::Dissatisfied->promptKey())->toBe('low');
});

it('moves a recovery case only along the workflow', function (): void {
    expect(RecoveryStatus::Open->next())->toContain(RecoveryStatus::InProgress, RecoveryStatus::Resolved)
        ->and(RecoveryStatus::Closed->next())->toBe([])
        ->and(RecoveryStatus::Resolved->next())->toContain(RecoveryStatus::Closed, RecoveryStatus::InProgress)
        ->and(RecoveryStatus::Resolved->isFinal())->toBeTrue()
        ->and(RecoveryStatus::PendingCustomer->isFinal())->toBeFalse();
});

it('computes NPS, CSAT and CES from the answers', function (): void {
    $rows = [];
    foreach ([10, 10, 9, 8, 7, 6, 2, 0, 9, 10] as $score) {
        $rows[] = ['survey_type' => 'nps', 'score' => $score];
    }
    foreach ([5, 4, 3, 1] as $score) {
        $rows[] = ['survey_type' => 'csat', 'score' => $score];
    }
    foreach ([1, 3, 4, 7] as $score) {
        $rows[] = ['survey_type' => 'ces', 'score' => (string) $score];
    }
    $rows[] = ['survey_type' => 'unknown', 'score' => 3];

    $kpis = KpiCalculator::compute($rows);

    // 5 promoters, 2 passives, 3 detractors out of 10: NPS = 50 - 30.
    expect($kpis['total'])->toBe(18)
        ->and($kpis['nps'])->toMatchArray(['responses' => 10, 'promoters' => 5, 'passives' => 2, 'detractors' => 3, 'promoters_pct' => 50.0, 'passives_pct' => 20.0, 'detractors_pct' => 30.0, 'score' => 20.0])
        ->and($kpis['csat']['positive_pct'])->toBe(50.0)
        ->and($kpis['csat']['average'])->toBe(3.25)
        ->and($kpis['ces']['average'])->toBe(3.75)
        ->and($kpis['ces']['low_effort_rate'])->toBe(50.0);
});

it('has no KPI before the first answer', function (): void {
    $kpis = KpiCalculator::compute([]);

    expect($kpis['total'])->toBe(0)
        ->and($kpis['nps']['score'])->toBeNull()
        ->and($kpis['csat']['positive_pct'])->toBeNull()
        ->and($kpis['csat']['average'])->toBeNull()
        ->and($kpis['ces']['average'])->toBeNull();
});

it('resolves the five survey locales with a fallback to the language and then to English', function (): void {
    expect(SurveyLocale::Supported)->toBe(['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'])
        ->and(SurveyLocale::normalize('it_CH'))->toBe('it-CH')
        ->and(SurveyLocale::normalize('de'))->toBe('de-CH')
        ->and(SurveyLocale::normalize('FR'))->toBe('fr-CH')
        ->and(SurveyLocale::normalize('it'))->toBe('it-IT')
        ->and(SurveyLocale::normalize('es'))->toBe('en')
        ->and(SurveyLocale::normalize(null))->toBe('en')
        ->and(SurveyLocale::forCountry('CH'))->toBe('it-CH')
        ->and(SurveyLocale::forCountry('IT'))->toBe('it-IT')
        ->and(SurveyLocale::forCountry('JP'))->toBe('en');

    $texts = ['en' => 'English', 'it-CH' => 'Svizzero', 'de-CH' => 'Schweizer'];

    expect(SurveyLocale::pick($texts, 'it-CH'))->toBe('Svizzero')
        ->and(SurveyLocale::pick($texts, 'it-IT'))->toBe('Svizzero')
        ->and(SurveyLocale::pick($texts, 'fr-CH'))->toBe('English')
        ->and(SurveyLocale::pick(['de-CH' => 'Nur'], 'fr-CH'))->toBe('Nur')
        ->and(SurveyLocale::pick(null, 'en'))->toBeNull();
});

it('scores the sentiment of a comment and finds its themes in the four languages', function (): void {
    $analyzer = new LexiconSentimentAnalyzer;

    $negative = $analyzer->analyze('The delivery was late and the support was slow. Very bad.', 'en');
    expect($negative['label'])->toBe('negative')
        ->and($negative['score'])->toBeLessThan(0)
        ->and($negative['topics'])->toContain('delivery', 'support');

    expect($analyzer->analyze('Ottimo servizio, tecnico veloce e gentile. Grazie!', 'it')['label'])->toBe('positive')
        ->and($analyzer->analyze('Der Techniker war freundlich und alles schnell.', 'de')['label'])->toBe('positive')
        ->and($analyzer->analyze('Livraison en retard, service mauvais.', 'fr')['label'])->toBe('negative')
        ->and($analyzer->analyze('We received a parcel.', 'en'))->toBe(['score' => 0.0, 'label' => 'neutral', 'topics' => []]);
});
