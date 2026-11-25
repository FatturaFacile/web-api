<?php

declare(strict_types=1);

use Database\Seeders\Translations\CustomerSatisfactionFrontendTranslations;
use Database\Seeders\Translations\EnglishFrontendTranslations;
use Database\Seeders\Translations\FrenchFrontendTranslations;
use Database\Seeders\Translations\GermanFrontendTranslations;
use Database\Seeders\Translations\ItalianFrontendTranslations;
use Modules\CustomerSatisfaction\Domain\Enums\DispatchStatus;
use Modules\CustomerSatisfaction\Domain\Enums\RecoveryPriority;
use Modules\CustomerSatisfaction\Domain\Enums\RecoveryStatus;
use Modules\CustomerSatisfaction\Domain\Enums\ReportGroupBy;
use Modules\CustomerSatisfaction\Domain\Enums\ResponseBand;
use Modules\CustomerSatisfaction\Domain\Enums\SubmittedVia;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyChannel;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyTriggerEvent;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyType;
use Modules\CustomerSatisfaction\Domain\Enums\ThrottleReason;
use Modules\CustomerSatisfaction\Exceptions\SatisfactionException;

it('carries every customer satisfaction key in the four languages with the same placeholders', function (): void {
    $definitions = CustomerSatisfactionFrontendTranslations::definitions();
    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $tuple) {
        expect($tuple)->toHaveCount(4);

        foreach ($tuple as $text) {
            expect(trim($text))->not->toBe('', $key);
        }

        preg_match_all('/\{(\w+)\}/', $tuple[0], $italian);
        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/\{(\w+)\}/', $text, $found);
            sort($found[1]);

            return $found[1];
        }, $tuple);

        sort($italian[1]);
        expect($placeholders[1])->toBe($italian[1], $key)
            ->and($placeholders[2])->toBe($italian[1], $key)
            ->and($placeholders[3])->toBe($italian[1], $key);
    }

    $keys = array_keys($definitions);

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(array_keys(CustomerSatisfactionFrontendTranslations::all($locale)))->toBe($keys);
    }
});

it('does not repeat a key of the base dictionaries', function (): void {
    $own = array_keys(CustomerSatisfactionFrontendTranslations::definitions());

    foreach ([ItalianFrontendTranslations::all(), EnglishFrontendTranslations::all(), GermanFrontendTranslations::all(), FrenchFrontendTranslations::all()] as $dictionary) {
        expect(array_intersect($own, array_keys($dictionary)))->toBe([]);
    }
});

it('rejects a language the dictionary does not have', function (): void {
    CustomerSatisfactionFrontendTranslations::all('es');
})->throws(InvalidArgumentException::class);

it('has a label for every value the customer satisfaction API reports', function (): void {
    $keys = array_keys(CustomerSatisfactionFrontendTranslations::definitions());

    $enums = [
        'surveyType' => SurveyType::class,
        'trigger' => SurveyTriggerEvent::class,
        'channel' => SurveyChannel::class,
        'dispatchStatus' => DispatchStatus::class,
        'throttleReason' => ThrottleReason::class,
        'band' => ResponseBand::class,
        'recoveryStatus' => RecoveryStatus::class,
        'priority' => RecoveryPriority::class,
        'dimension' => ReportGroupBy::class,
        'submittedVia' => SubmittedVia::class,
    ];

    foreach ($enums as $group => $enum) {
        foreach ($enum::cases() as $case) {
            expect($keys)->toContain("customerSatisfaction.{$group}.{$case->value}");
        }
    }

    foreach (['low', 'medium', 'high', 'critical'] as $risk) {
        expect($keys)->toContain("customerSatisfaction.churn.{$risk}");
    }

    foreach (['day', 'week', 'month', 'quarter'] as $granularity) {
        expect($keys)->toContain("customerSatisfaction.granularity.{$granularity}");
    }

    foreach (config('customer_satisfaction.root_causes') as $cause) {
        expect($keys)->toContain("customerSatisfaction.rootCause.{$cause}");
    }

    foreach (['nps', 'csat', 'ces'] as $type) {
        expect($keys)->toContain("customerSatisfaction.public.pickScore.{$type}");
    }
});

it('translates every error code the API reports', function (): void {
    $keys = array_keys(CustomerSatisfactionFrontendTranslations::definitions());

    foreach (['survey_not_found', 'survey_expired', 'survey_not_available', 'invalid_score', 'score_required', 'comment_required', 'voice_not_allowed', 'invalid_status_transition', 'resolution_requires_root_cause', 'unsupported_export_format'] as $code) {
        expect($keys)->toContain("customerSatisfaction.error.{$code}");

        foreach (['en', 'it', 'de', 'fr'] as $locale) {
            app()->setLocale($locale);
            expect(__("customer_satisfaction.errors.{$code}"))->not->toBe("customer_satisfaction.errors.{$code}", "{$locale} {$code}");
        }
    }

    $problem = (new SatisfactionException('invalid_score', 422, ['min' => 0, 'max' => 10]))->render(request());
    expect($problem->getStatusCode())->toBe(422)->and($problem->getData(true)['code'])->toBe('invalid_score');
});

it('keeps the backend dictionaries of the four languages in step', function (): void {
    $flatten = static function (array $tree, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            array_push($keys, ...(is_array($value) ? $flatten($value, $path) : [$path]));
        }

        return $keys;
    };

    $english = $flatten(require base_path('lang/en/customer_satisfaction.php'));
    expect($english)->not->toBeEmpty();

    foreach (['it', 'de', 'fr'] as $locale) {
        $other = $flatten(require base_path("lang/{$locale}/customer_satisfaction.php"));
        sort($english);
        sort($other);
        expect($other)->toBe($english, $locale);
    }
});
