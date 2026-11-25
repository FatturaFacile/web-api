<?php

declare(strict_types=1);

use Database\Seeders\DebtCollectionReferenceSeeder;
use Database\Seeders\Translations\DebtCollectionFrontendTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\DebtCollection\Models\InterestRate;
use Modules\DebtCollection\Models\Template;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new DebtCollectionReferenceSeeder)->run();
});

it('seeds the notice texts of every level and language, with the legal variants of level 3', function (): void {
    $expected = [
        'level_0' => [null], 'level_1' => [null], 'level_2' => [null],
        'level_3' => ['CH', 'IT'], 'messa_in_mora' => ['IT'], 'labels' => [null], 'betreibung' => [null],
    ];

    foreach ($expected as $code => $jurisdictions) {
        foreach ($jurisdictions as $jurisdiction) {
            foreach (['it', 'en', 'de', 'fr'] as $locale) {
                $template = Template::query()->whereNull('tenant_id')->where('code', $code)->where('locale', $locale)->where('jurisdiction', $jurisdiction)->first();

                expect($template, "{$code} {$locale} {$jurisdiction}")->not->toBeNull()
                    ->and(trim((string) $template->body), "{$code} {$locale} body")->not->toBe('');
            }
        }
    }
});

it('uses only placeholders the notice builder fills', function (): void {
    $known = ['customer_name', 'company_name', 'notice_number', 'issued_on', 'new_due_date', 'currency', 'principal', 'interest', 'fee', 'total', 'interest_rate', 'legal_basis', 'items'];

    foreach (Template::query()->whereNull('tenant_id')->whereNotIn('code', ['labels', 'betreibung'])->get() as $template) {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $template->subject.' '.$template->body, $matches);

        expect(array_diff($matches[1], $known), "{$template->code} {$template->locale}")->toBe([]);
    }
});

it('seeds the same captions in every language for the notice and the Betreibung documents', function (): void {
    foreach (['labels', 'betreibung'] as $code) {
        $keys = [];

        foreach (['it', 'en', 'de', 'fr'] as $locale) {
            $labels = json_decode((string) Template::query()->whereNull('tenant_id')->where('code', $code)->where('locale', $locale)->value('body'), true);
            $keys[$locale] = array_keys((array) $labels);
            sort($keys[$locale]);
        }

        expect($keys['en'])->toBe($keys['it'])->and($keys['de'])->toBe($keys['it'])->and($keys['fr'])->toBe($keys['it']);
    }
});

it('seeds the statutory rates: 5% of Art. 104 CO and the ECB reference rate plus 8 points since 2013, without gaps', function (): void {
    $swiss = InterestRate::query()->whereNull('tenant_id')->where('jurisdiction', 'CH')->get();

    expect($swiss)->toHaveCount(1)->and((string) $swiss->first()->base_rate_percent)->toBe('5.0000');

    $italian = InterestRate::query()->whereNull('tenant_id')->where('jurisdiction', 'IT')->orderBy('effective_from')->get();

    expect($italian->first()->effective_from->toDateString())->toBe('2013-01-01')
        ->and($italian->last()->effective_to)->toBeNull();

    foreach ($italian as $index => $rate) {
        expect((string) $rate->markup_percent)->toBe('8.0000');

        if ($index > 0) {
            expect(Carbon::instance($italian[$index - 1]->effective_to)->addDay()->toDateString())->toBe($rate->effective_from->toDateString());
        }
    }

    $first = $italian->firstWhere('effective_from', Carbon::parse('2023-07-01'));

    expect((string) $first->base_rate_percent)->toBe('4.0000'); // 4.00 + 8 = 12.00%
});

it('is idempotent', function (): void {
    $templates = Template::query()->count();
    $rates = InterestRate::query()->count();

    (new DebtCollectionReferenceSeeder)->run();

    expect(Template::query()->count())->toBe($templates)->and(InterestRate::query()->count())->toBe($rates);
});

it('translates every interface text of the module in all four languages', function (): void {
    $definitions = DebtCollectionFrontendTranslations::definitions();

    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $texts) {
        expect($key)->toStartWith('debtCollection.')->and($texts)->toHaveCount(4);

        foreach ($texts as $index => $text) {
            expect(trim($text), "{$key} [{$index}]")->not->toBe('');
        }
    }

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(array_keys(DebtCollectionFrontendTranslations::all($locale)))->toBe(array_keys($definitions));
    }
});

it('keeps the same placeholders in every language of an interface text', function (): void {
    foreach (DebtCollectionFrontendTranslations::definitions() as $key => $texts) {
        $sets = array_map(static function (string $text): array {
            preg_match_all('/(\{\{?\s*\w+\s*\}?\}|:\w+)/', $text, $matches);
            sort($matches[1]);

            return $matches[1];
        }, $texts);

        expect($sets[1], $key)->toBe($sets[0])->and($sets[2], $key)->toBe($sets[0])->and($sets[3], $key)->toBe($sets[0]);
    }
});
