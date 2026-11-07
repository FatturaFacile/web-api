<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Customs\Enums\DeclarationType;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsDossier;
use Modules\Customs\Models\CustomsPackagingType;
use Modules\Customs\Models\CustomsTariffGrid;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

afterEach(fn () => app(TenantContext::class)->clear());

test('the factories build records of the tenant they are given', function (): void {
    app(TenantContext::class)->clear();
    $tenant = customsTenant();

    $declaration = CustomsDeclaration::factory()->transit()->create(['tenant_id' => $tenant->id]);
    $dossier = CustomsDossier::factory()->create(['tenant_id' => $tenant->id]);
    $grid = CustomsTariffGrid::factory()->create(['tenant_id' => $tenant->id]);
    $packaging = CustomsPackagingType::factory()->generic()->create();

    app(TenantContext::class)->setTenant($tenant);

    expect($declaration->declaration_type)->toBe(DeclarationType::Transit)
        ->and($dossier->customer->tenant_id)->toBe($tenant->id)
        ->and($grid->tenant_id)->toBe($tenant->id)
        ->and($packaging->isGeneric())->toBeTrue();
});
