<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function gelatoTenant(): Tenant
{
    return Tenant::create([
        'name' => 'Gelateria '.Str::random(5),
        'legal_name' => 'Gelateria SA',
        'vat_number' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'fiscal_code' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);
}

function gelatoActAs(Tenant $tenant, string $role = 'owner'): void
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function gelatoIngredient(string $code, array $overrides = []): string
{
    $response = test()->postJson('/api/v1/gelato/ingredients', $overrides + [
        'code' => $code,
        'name' => ['it' => $code, 'de' => $code, 'fr' => $code, 'en' => $code],
        'category' => 'SUGAR',
        'pod' => 100,
        'pac' => 100,
        'total_solids_percent' => 100,
        'water_percent' => 0,
        'cost_per_kg' => 1.1,
    ])->assertCreated();

    return (string) $response->json('data.id');
}

/**
 * @return array<string, mixed>
 */
function gelatoRecipePayload(string $code, array $lines): array
{
    return [
        'code' => $code,
        'name' => ['it' => $code, 'de' => $code, 'fr' => $code, 'en' => $code],
        'category' => 'DAIRY_CREAM',
        'kind' => 'flavor',
        'lines' => $lines,
    ];
}

it('previews a recipe and stores its calculated vector', function (): void {
    gelatoActAs(gelatoTenant());
    $sugar = gelatoIngredient('SUGAR_T');
    $milk = gelatoIngredient('MILK_T', ['category' => 'DAIRY', 'pod' => 0.8, 'pac' => 0, 'milk_fat_percent' => 3.6, 'slng_percent' => 8.6, 'total_solids_percent' => 12.2, 'water_percent' => 87.8, 'allergens' => ['milk'], 'is_vegan' => false]);
    $lines = [['ingredient_id' => $sugar, 'grams' => 160], ['ingredient_id' => $milk, 'grams' => 840]];

    $this->postJson('/api/v1/gelato/recipes/balance', ['category' => 'DAIRY_CREAM', 'lines' => $lines])
        ->assertOk()
        ->assertJsonPath('data.mode', 'preview')
        ->assertJsonPath('data.metrics.pod', 166.72);

    $this->postJson('/api/v1/gelato/recipes', gelatoRecipePayload('R1', $lines))
        ->assertCreated()
        ->assertJsonPath('data.calculated_pod_percent', 166.72)
        ->assertJsonPath('data.allergens', ['milk'])
        ->assertJsonPath('data.dietary.lactose_free', false);
});

it('auto-balances towards targets through the API', function (): void {
    gelatoActAs(gelatoTenant());
    $sucrose = gelatoIngredient('SUCROSE_T');
    $dextrose = gelatoIngredient('DEXTROSE_T', ['pod' => 70, 'pac' => 190]);
    $water = gelatoIngredient('WATER_T', ['category' => 'WATER', 'pod' => 0, 'pac' => 0, 'total_solids_percent' => 0, 'water_percent' => 100]);

    $this->postJson('/api/v1/gelato/recipes/balance', [
        'category' => 'FRUIT_SORBET',
        'targets' => ['weight' => 1000, 'pod' => 160, 'pac' => 240],
        'pool' => [['ingredient_id' => $sucrose], ['ingredient_id' => $dextrose], ['ingredient_id' => $water]],
    ])->assertOk()->assertJsonPath('data.status', 'EXACT');
});

it('clones and scales a recipe', function (): void {
    gelatoActAs(gelatoTenant());
    $sugar = gelatoIngredient('SUGAR_S');
    $id = $this->postJson('/api/v1/gelato/recipes', gelatoRecipePayload('R2', [['ingredient_id' => $sugar, 'grams' => 1000]]))->json('data.id');

    $this->postJson("/api/v1/gelato/recipes/{$id}/clone", ['code' => 'R2-B'])->assertCreated()->assertJsonPath('data.code', 'R2-B');
    $this->postJson("/api/v1/gelato/recipes/{$id}/scale", ['batch_weight_grams' => 3500])
        ->assertOk()
        ->assertJsonPath('data.lines.0.grams', 3500);
});

it('runs the two-stage lot genealogy and the HACCP gate', function (): void {
    gelatoActAs(gelatoTenant());
    $base = $this->postJson('/api/v1/gelato/lots', ['kind' => 'base', 'method' => 'two_stage', 'mix_weight_grams' => 60000, 'mix_volume_liters' => 55])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/gelato/lots/{$base}/complete", ['yield_grams' => 60000])->assertStatus(422);

    $this->postJson("/api/v1/gelato/lots/{$base}/haccp-readings", ['readings' => [
        ['at' => '2027-03-01 08:00:00', 'temperature_celsius' => 86, 'phase' => 'heat'],
        ['at' => '2027-03-01 08:40:00', 'temperature_celsius' => 4, 'phase' => 'cool'],
    ]])->assertOk()->assertJsonPath('data.haccp_passed', true);
    $this->postJson("/api/v1/gelato/lots/{$base}/complete", ['yield_grams' => 60000])->assertOk()->assertJsonPath('data.status', 'completed');

    $flavor = $this->postJson('/api/v1/gelato/lots', [
        'kind' => 'flavor', 'method' => 'two_stage', 'parent_lot_id' => $base,
        'consumptions' => [['source_lot_id' => $base, 'grams' => 3500]],
    ])->assertCreated()->json('data.id');

    $this->postJson('/api/v1/gelato/lots', [
        'kind' => 'flavor', 'parent_lot_id' => $base,
        'consumptions' => [['source_lot_id' => $base, 'grams' => 70000]],
    ])->assertStatus(422);

    $this->getJson("/api/v1/gelato/lots/{$base}/genealogy")->assertOk()->assertJsonPath('data.descendants.0.id', $flavor);
    $this->getJson("/api/v1/gelato/lots/{$flavor}/genealogy")->assertOk()->assertJsonPath('data.ancestors.0.id', $base);
});

it('publishes the showcase with descending ingredients, allergens and the defrosted notice in four languages', function (): void {
    gelatoActAs(gelatoTenant());
    $sugar = gelatoIngredient('SUGAR_P');
    $paste = gelatoIngredient('PASTE_P', ['category' => 'NUT_PASTE', 'allergens' => ['nuts'], 'is_defrosted' => true, 'name' => ['it' => 'Pistacchio', 'de' => 'Pistazie', 'fr' => 'Pistache', 'en' => 'Pistachio']]);
    $recipe = $this->postJson('/api/v1/gelato/recipes', gelatoRecipePayload('PIST', [['ingredient_id' => $sugar, 'grams' => 300], ['ingredient_id' => $paste, 'grams' => 700]]))->json('data.id');

    $this->postJson('/api/v1/gelato/showcase/main/publish', ['jurisdiction' => 'CH', 'slots' => [['slot_number' => 1, 'recipe_id' => $recipe]]])
        ->assertCreated()
        ->assertJsonPath('data.version', 1);

    $this->getJson('/api/v1/gelato/showcase/main/book?locale=de')
        ->assertOk()
        ->assertJsonPath('data.books.de.slots.0.ingredients.0.name', 'Pistazie')
        ->assertJsonPath('data.books.de.slots.0.allergens.0.name', 'Schalenfrüchte')
        ->assertJsonPath('data.books.de.slots.0.defrosted_notice', 'Aufgetaut')
        ->assertJsonMissingPath('data.books.it');

    $this->postJson('/api/v1/gelato/showcase/main/publish', ['slots' => [['slot_number' => 1, 'recipe_id' => $recipe], ['slot_number' => 1, 'recipe_id' => $recipe]]])->assertStatus(422);
});

it('keeps recipes and ingredients isolated per tenant', function (): void {
    gelatoActAs(gelatoTenant());
    $sugar = gelatoIngredient('SUGAR_I');
    $id = $this->postJson('/api/v1/gelato/recipes', gelatoRecipePayload('ISO', [['ingredient_id' => $sugar, 'grams' => 1000]]))->json('data.id');

    gelatoActAs(gelatoTenant());

    $this->getJson("/api/v1/gelato/recipes/{$id}")->assertNotFound();
    $this->getJson('/api/v1/gelato/recipes')->assertOk()->assertJsonCount(0, 'data');
});

it('moves orders along their lifecycle and refuses invalid transitions', function (): void {
    gelatoActAs(gelatoTenant());
    $id = $this->postJson('/api/v1/gelato/orders', [
        'kind' => 'gelato_cake', 'customer_name' => 'Rossi', 'total_price' => 45,
        'components' => [['label' => 'Cake base', 'quantity' => 1, 'unit_cost' => 12]],
    ])->assertCreated()->assertJsonPath('data.component_cost', 12)->json('data.id');

    $this->patchJson("/api/v1/gelato/orders/{$id}/status", ['status' => 'ready'])->assertStatus(422);
    $this->patchJson("/api/v1/gelato/orders/{$id}/status", ['status' => 'confirmed'])->assertOk()->assertJsonPath('data.status', 'confirmed');
});

it('stores typed scale readings and refuses an empty manual reading', function (): void {
    gelatoActAs(gelatoTenant());

    $this->postJson('/api/v1/gelato/scale-readings', ['grams' => 3500.5])->assertCreated()->assertJsonPath('data.source', 'manual');
    $this->postJson('/api/v1/gelato/scale-readings', [])->assertStatus(422);
});
