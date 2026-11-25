<?php

declare(strict_types=1);

use App\Models\Translation;
use Database\Seeders\CpqConfiguratorDemoSeeder;
use Database\Seeders\CpqConfiguratorLocalizationSeeder;
use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\CpqConfiguratorFrontendTranslations;
use Database\Seeders\Translations\CpqConfiguratorPdfTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqCharacteristicOption;
use Modules\CpqConfigurator\Models\CpqConstraintRule;
use Modules\CpqConfigurator\Models\CpqModel;
use Modules\CpqConfigurator\Models\CpqPricingFormula;
use Modules\CpqConfigurator\Models\CpqRoutingMapping;
use Modules\CpqConfigurator\Models\CpqSuperBomMapping;
use Modules\CpqConfigurator\Services\CpqModelService;

require_once __DIR__.'/CpqConfiguratorSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    cpqSetUp($this, 'owner');
});

/**
 * @return array<string, int>
 */
function cpqCounts(): array
{
    return [
        'models' => CpqModel::query()->count(),
        'characteristics' => CpqCharacteristic::query()->count(),
        'options' => CpqCharacteristicOption::query()->count(),
        'rules' => CpqConstraintRule::query()->count(),
        'bom' => CpqSuperBomMapping::query()->count(),
        'routing' => CpqRoutingMapping::query()->count(),
        'pricing' => CpqPricingFormula::query()->count(),
    ];
}

it('seeds two published demo models with the full definition', function (): void {
    $counts = cpqCounts();

    expect($counts['models'])->toBe(2)
        ->and($counts['characteristics'])->toBeGreaterThanOrEqual(18)
        ->and($counts['options'])->toBeGreaterThanOrEqual(30)
        ->and($counts['rules'])->toBeGreaterThanOrEqual(12)
        ->and($counts['bom'])->toBeGreaterThanOrEqual(20)
        ->and($counts['routing'])->toBeGreaterThanOrEqual(7)
        ->and($counts['pricing'])->toBeGreaterThanOrEqual(9)
        ->and(CpqModel::query()->where('status', 'published')->count())->toBe(2)
        ->and(CpqModel::query()->where('code', 'PUMP-SKID')->firstOrFail()->part_number_prefix)->toBe('PSK');
});

it('is idempotent: a second run changes nothing and keeps the version and the sequence of a model in use', function (): void {
    $before = cpqCounts();
    $model = cpqModel($this);
    $model->forceFill(['version' => 7, 'part_number_sequence' => 12])->save();

    cpqInTenant($this->tenant, fn () => (new CpqConfiguratorDemoSeeder)->seedTenant());
    cpqInTenant($this->tenant, fn () => (new CpqConfiguratorDemoSeeder)->seedTenant());

    $after = cpqModel($this);

    expect(cpqCounts())->toBe($before)
        ->and($after->version)->toBe(7)
        ->and($after->part_number_sequence)->toBe(12)
        ->and($after->status)->toBe('published');
});

it('seeds the texts of the configurator in the four languages, never empty', function (): void {
    $missing = [];
    $check = static function (string $where, mixed $map) use (&$missing): void {
        foreach (['it', 'en', 'de', 'fr'] as $locale) {
            if (! is_array($map) || ! is_string($map[$locale] ?? null) || trim($map[$locale]) === '') {
                $missing[] = "{$where}:{$locale}";
            }
        }
    };

    foreach (CpqModel::query()->get() as $model) {
        $check("model {$model->code} name", $model->name);
        $check("model {$model->code} description", $model->description);

        foreach ($model->steps ?? [] as $step) {
            $check("{$model->code} step {$step['code']}", $step['title']);
        }

        foreach (['title', 'intro', 'warranty', 'terms', 'delivery', 'installation'] as $text) {
            $check("{$model->code} proposal {$text}", $model->proposal_texts[$text] ?? null);
        }

        foreach ($model->proposal_texts['dimensional_notes'] ?? [] as $index => $note) {
            $check("{$model->code} dimensional note {$index}", $note);
        }
    }

    foreach (CpqCharacteristic::query()->get() as $characteristic) {
        $check("characteristic {$characteristic->code}", $characteristic->label);
    }

    foreach (CpqCharacteristicOption::query()->get() as $option) {
        $check("option {$option->code}", $option->label);
    }

    foreach (CpqConstraintRule::query()->where('rule_type', '!=', 'hide')->get() as $rule) {
        $check("rule {$rule->code}", $rule->message);
    }

    foreach (CpqSuperBomMapping::query()->get() as $mapping) {
        $check("bom {$mapping->code}", $mapping->item_name);
    }

    foreach (CpqRoutingMapping::query()->get() as $mapping) {
        $check("routing {$mapping->code}", $mapping->operation_name);
    }

    foreach (CpqPricingFormula::query()->get() as $formula) {
        $check("pricing {$formula->code}", $formula->label);
    }

    expect($missing)->toBe([]);
});

it('seeds models that pass the static check and can be evaluated and confirmed as they are', function (): void {
    foreach (CpqModel::query()->get() as $model) {
        expect(app(CpqModelService::class)->diagnostics($model))->toBe([], "Diagnostics of {$model->code}");
    }

    $panel = cpqModel($this, 'ELEC-PANEL');
    $evaluation = $this->postJson(cpqUrl("/models/{$panel->id}/evaluate"), ['selections' => ['destination_country' => 'CH']])->assertOk()->json('data');

    expect($evaluation['valid'])->toBeTrue()
        ->and($evaluation['complete'])->toBeTrue()
        ->and($evaluation['auto_included'][0]['characteristic'])->toBe('esti_pack')
        ->and(array_column($evaluation['bom'], 'item_code'))->toContain('PACK-ESTI', 'BRK-FEEDER');
    $this->postJson(cpqUrl("/models/{$panel->id}/confirm"), ['selections' => ['destination_country' => 'CH', 'feeders' => 12]])->assertCreated();
});

it('prices the pump skid with the documented formulas', function (): void {
    $pump = cpqModel($this);
    $data = $this->postJson(cpqUrl("/models/{$pump->id}/evaluate"), ['selections' => cpqPumpSelections(['destination_country' => 'CH', 'atex_zone' => 'ZONE_2', 'warranty_years' => 4, 'control_panel' => 'VFD', 'cable_length_m' => 20]), 'quantity' => 5])->assertOk()->json('data');

    $components = array_column($data['pricing']['components'], 'value', 'code');

    expect($data['valid'])->toBeTrue()
        ->and($data['auto_included'])->toHaveCount(2)
        ->and($components['atex-surcharge'])->toEqual(1500)
        ->and($components['ch-compliance-fee'])->toEqual(350)
        ->and($components['ch-certification-cost'])->toEqual(120)
        ->and($components['volume-discount'])->toEqual(3)
        ->and($components['warranty-extension'])->toBeGreaterThan(0)
        ->and($data['totals']['discount_percent'])->toEqual(3)
        ->and(array_column($data['bom'], 'item_code'))->toContain('MOTOR-EXD', 'PANEL-VFD', 'KIT-SAFETY', 'LABEL-ESTI')
        ->and(array_column($data['routing'], 'code'))->toContain('atex-certification');
});

it('seeds the proposal labels of the PDF in the four languages and replaces them on a second run', function (): void {
    (new CpqConfiguratorLocalizationSeeder)->run();
    (new CpqConfiguratorLocalizationSeeder)->run();

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $texts = CpqConfiguratorPdfTranslations::all($locale);

        expect(Translation::query()->whereNull('tenant_id')->where('locale', $locale)->where('key', 'like', 'PDF.CPQ_PROPOSAL.%')->count())->toBe(count($texts))
            ->and(array_filter($texts, static fn (string $text): bool => trim($text) === ''))->toBe([]);
    }
});

it('has the same keys and no empty text in every language of the frontend dictionary', function (): void {
    $reference = array_keys(CpqConfiguratorFrontendTranslations::all('it'));

    expect($reference)->not->toBeEmpty();

    foreach (['en', 'de', 'fr'] as $locale) {
        $texts = CpqConfiguratorFrontendTranslations::all($locale);

        expect(array_keys($texts))->toBe($reference)
            ->and(array_filter($texts, static fn (string $text): bool => trim($text) === ''))->toBe([]);
    }

    foreach (CpqConfiguratorFrontendTranslations::definitions() as $key => $texts) {
        expect($key)->toStartWith('CPQ_CONFIGURATOR.');
    }
});

it('serves the frontend dictionary of the configurator with the other frontend texts, without overlapping them', function (): void {
    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $served = FrontendTranslationSeeder::dictionary($locale);

        foreach (CpqConfiguratorFrontendTranslations::all($locale) as $key => $text) {
            expect($served[$key] ?? null)->toBe($text);
        }
    }

    $others = array_diff_key(
        FrontendTranslationSeeder::dictionary('en'),
        CpqConfiguratorFrontendTranslations::all('en'),
    );

    expect(array_filter(array_keys($others), static fn (string $key): bool => str_starts_with($key, 'CPQ_CONFIGURATOR.')))->toBe([]);
});
