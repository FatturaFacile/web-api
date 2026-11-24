<?php

declare(strict_types=1);

use Database\Seeders\MarketingAutomationSeeder;
use Database\Seeders\Translations\MarketingAutomationFrontendTranslations;
use Illuminate\Support\Facades\DB;
use Modules\MarketingAutomation\Models\MarketingBlueprint;

const MA_LOCALES = ['it', 'de', 'fr', 'en'];

it('seeds the blueprints idempotently as global rows', function (): void {
    $this->seed(MarketingAutomationSeeder::class);
    $first = DB::table('ma_blueprints')->count();

    $this->seed(MarketingAutomationSeeder::class);

    expect($first)->toBeGreaterThan(0)
        ->and(DB::table('ma_blueprints')->count())->toBe($first)
        ->and(DB::table('ma_blueprints')->whereNotNull('tenant_id')->count())->toBe(0);
});

it('provides every blueprint text in it, de, fr and en', function (): void {
    $this->seed(MarketingAutomationSeeder::class);

    $blueprints = DB::table('ma_blueprints')->get();
    expect($blueprints)->not->toBeEmpty();

    foreach ($blueprints as $row) {
        $texts = json_decode((string) $row->texts, true);
        foreach (MA_LOCALES as $locale) {
            expect($texts)->toHaveKey($locale, "{$row->kind}:{$row->code} lacks {$locale}");
            foreach ($texts[$locale] as $field => $value) {
                expect(trim((string) $value))->not->toBe('', "{$row->kind}:{$row->code}.{$field} empty in {$locale}");
            }
        }
    }
});

it('seeds the compliance footers required by both privacy strategies', function (): void {
    $this->seed(MarketingAutomationSeeder::class);

    foreach (['footer.sender_identity', 'footer.unsubscribe', 'footer.privacy_ch', 'footer.privacy_it', 'footer.chat_optout'] as $code) {
        expect(MarketingBlueprint::query()->where('kind', 'text')->where('code', $code)->exists())->toBeTrue("missing {$code}");
    }
});

it('seeds the Mod 155 starter presets, templates and journeys', function (): void {
    $this->seed(MarketingAutomationSeeder::class);

    expect(MarketingBlueprint::query()->where('kind', 'segment_preset')->count())->toBeGreaterThanOrEqual(6)
        ->and(MarketingBlueprint::query()->where('kind', 'email_template')->count())->toBeGreaterThanOrEqual(3)
        ->and(MarketingBlueprint::query()->where('kind', 'journey')->count())->toBeGreaterThanOrEqual(4);
});

it('keeps the frontend translations complete in all four languages', function (): void {
    $definitions = MarketingAutomationFrontendTranslations::definitions();

    expect($definitions)->not->toBeEmpty();
    foreach ($definitions as $key => $tuple) {
        expect($tuple)->toHaveCount(4, $key);
        foreach ($tuple as $text) {
            expect(trim((string) $text))->not->toBe('', $key);
        }
    }
});
