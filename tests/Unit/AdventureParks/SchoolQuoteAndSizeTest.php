<?php

declare(strict_types=1);

use Modules\AdventureParks\Domain\Enums\PpeSize;
use Modules\AdventureParks\Domain\Services\SchoolQuoteCalculator;
use Modules\AdventureParks\Domain\Services\SizeRecommender;

describe('free teachers of a school trip', function (): void {
    it('lets one teacher travel free every 15 paying students plus one support teacher per student with a disability', function (int $paying, int $disabled, int $teachers, int $free, int $charged): void {
        $quote = (new SchoolQuoteCalculator(15, 1))->quote($paying, $disabled, $teachers, []);

        expect($quote['free_teachers'])->toBe($free)->and($quote['charged_teachers'])->toBe($charged);
    })->with([
        'under the ratio' => [14, 0, 2, 0, 2],
        'exactly one ratio' => [15, 0, 2, 1, 1],
        'two ratios and two teachers' => [45, 0, 2, 2, 0],
        'never more than the teachers who come' => [150, 0, 3, 3, 0],
        'support teachers' => [20, 2, 3, 3, 0],
        'support teachers capped by the teachers who come' => [20, 2, 1, 1, 0],
        'a class of one with a disabled mate' => [1, 1, 1, 1, 0],
        'no teacher' => [30, 0, 0, 0, 0],
    ]);

    it('follows the ratio of the park configuration', function (): void {
        $quote = (new SchoolQuoteCalculator(10, 1))->quote(30, 0, 4, []);

        expect($quote['free_teachers'])->toBe(3)->and($quote['charged_teachers'])->toBe(1);
    });

    it('refuses a ratio below one', function (): void {
        new SchoolQuoteCalculator(0);
    })->throws(InvalidArgumentException::class);
});

describe('picnic seats and total', function (): void {
    it('seats the students, the students with a disability and every teacher', function (): void {
        expect((new SchoolQuoteCalculator)->quote(30, 1, 4, [])['picnic_seats'])->toBe(35);
    });

    it('charges every workshop for the paying students and the charged teachers, not for the free ones', function (): void {
        $quote = (new SchoolQuoteCalculator(15, 1))->quote(30, 0, 4, ['8.00', '7.50']);

        // 2 free teachers, 2 charged: 32 heads x (8.00 + 7.50)
        expect($quote['total_amount'])->toBe('496.00')->and($quote['free_teachers'])->toBe(2)->and($quote['charged_teachers'])->toBe(2);
    });

    it('works in cents and writes two decimals', function (): void {
        expect((new SchoolQuoteCalculator)->quote(3, 0, 0, ['0.34'])['total_amount'])->toBe('1.02')
            ->and((new SchoolQuoteCalculator)->quote(3, 0, 0, [])['total_amount'])->toBe('0.00');
    });
});

describe('harness size recommendation', function (): void {
    it('picks the smallest size whose maxima cover the height and the weight', function (float $height, float $weight, PpeSize $expected): void {
        expect(SizeRecommender::fromConfig()->recommend($height, $weight))->toBe($expected);
    })->with([
        'toddler' => [90, 14, PpeSize::Baby],
        'edge of baby' => [110, 25, PpeSize::Baby],
        'child' => [125, 30, PpeSize::Junior],
        'tall but light child' => [135, 44, PpeSize::Junior],
        'junior height but heavy' => [135, 50, PpeSize::Adult],
        'adult' => [175, 70, PpeSize::Adult],
        'edge of adult' => [190, 100, PpeSize::Adult],
        'heavy adult' => [185, 101, PpeSize::Xl],
        'very tall adult' => [195, 80, PpeSize::Xl],
        'beyond the table' => [240, 200, PpeSize::Xl],
        'below the table' => [60, 5, PpeSize::Baby],
    ]);

    it('uses the table it is given and exposes it as rows', function (): void {
        $recommender = new SizeRecommender([
            'BABY' => ['min_height_cm' => 80, 'max_height_cm' => 100, 'min_weight_kg' => 8, 'max_weight_kg' => 20],
            'ADULT' => ['min_height_cm' => 100, 'max_height_cm' => 200, 'min_weight_kg' => 20, 'max_weight_kg' => 120],
        ]);

        expect($recommender->recommend(95, 15))->toBe(PpeSize::Baby)
            ->and($recommender->recommend(150, 60))->toBe(PpeSize::Adult)
            ->and($recommender->recommend(210, 60))->toBe(PpeSize::Xl)
            ->and($recommender->table()[1])->toBe(['size' => 'ADULT', 'min_height_cm' => 100, 'max_height_cm' => 200, 'min_weight_kg' => 20, 'max_weight_kg' => 120]);
    });
});
