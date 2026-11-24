<?php

declare(strict_types=1);

use App\Models\Tenant;
use Enterprise\FeatureFlags\Contracts\FeatureManagerInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

test('feature manager resolves tenant scoped flags with database persistence and hierarchy', function () {
    $manager = app(FeatureManagerInterface::class);

    $tenantA = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Flag Tenant A',
        'legal_name' => 'Flag Tenant A S.r.l.',
        'vat_number' => 'IT88888888888',
        'status' => 'active',
    ]);

    $tenantB = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Flag Tenant B',
        'legal_name' => 'Flag Tenant B S.r.l.',
        'vat_number' => 'IT99999999999',
        'status' => 'active',
    ]);

    // Enable feature only for Tenant A
    $manager->enable('ai_copilot_v2', $tenantA);
    $manager->disable('ai_copilot_v2', $tenantB);

    expect($manager->isEnabled('ai_copilot_v2', $tenantA))->toBeTrue()
        ->and($manager->isEnabled('ai_copilot_v2', $tenantB))->toBeFalse();

    // Runtime override takes top priority
    $manager->enable('ai_copilot_v2', 'runtime');
    expect($manager->isEnabled('ai_copilot_v2', $tenantB))->toBeTrue();
});
