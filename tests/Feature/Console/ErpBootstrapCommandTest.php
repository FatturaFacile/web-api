<?php

declare(strict_types=1);

use App\Models\Translation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the global UI translations, which demo:reset relies on after migrate:fresh', function (): void {
    $this->artisan('erp:bootstrap', ['--without-demo' => true, '--force' => true])->assertSuccessful();

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(Translation::query()->whereNull('tenant_id')->where('locale', $locale)->count())->toBeGreaterThan(0);
    }

    $response = $this->getJson('/api/v1/translations/it')->assertOk()->assertJsonPath('locale', 'it');

    expect($response->json('translations'))->not->toBeEmpty();
});
