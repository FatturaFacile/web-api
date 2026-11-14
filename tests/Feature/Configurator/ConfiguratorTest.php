<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Configurator\Models\ProductConfiguration;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->tenant = Tenant::create(['name' => 'Officine Rossi', 'legal_name' => 'Officine Rossi Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->admin = configuratorMemberOf($this->tenant, 'admin');
    $this->sales = configuratorMemberOf($this->tenant, 'sales');
    $this->manager = configuratorMemberOf($this->tenant, 'sales_manager');
    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Trasporti Bianchi Spa', 'country' => 'IT']);
});

afterEach(fn () => app(TenantContext::class)->clear());

function configuratorMemberOf(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.it', 'password' => bcrypt(Str::password(24)), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function configuratorActAs(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);
    test()->flushHeaders();

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

/**
 * Builds the reference model through the API as an administrator and returns its id with the ids of the groups and options by code.
 *
 * @return array{id: string, groups: array<string, string>, options: array<string, string>}
 */
function configuratorBuildModel(string $code = 'TRK-1'): array
{
    $model = test()->postJson('/api/v1/configurator/models', [
        'code' => $code, 'name' => 'Electric truck', 'base_price' => 1000, 'base_cost' => 600, 'tax_rate' => 22, 'currency' => 'eur', 'min_margin_percent' => 15,
        'volume_breaks' => [['min_quantity' => 10, 'discount_percent' => 5]],
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.currency', 'EUR')->json('data');

    $groups = [];
    $options = [];
    $definition = [
        ['code' => 'size', 'name' => 'Size', 'type' => 'single', 'is_required' => true, 'options' => [
            ['code' => 'S', 'name' => 'Small', 'is_default' => true],
            ['code' => 'M', 'name' => 'Medium', 'price_delta' => 100, 'cost' => 60],
            ['code' => 'L', 'name' => 'Large', 'price_delta' => 250, 'cost' => 150],
        ]],
        ['code' => 'extras', 'name' => 'Extras', 'type' => 'multi', 'options' => [
            ['code' => 'GPS', 'name' => 'GPS tracker', 'price_delta' => 200, 'cost' => 120],
            ['code' => 'RADIO', 'name' => 'Radio', 'price_delta' => 80, 'cost' => 40],
        ]],
        ['code' => 'length', 'name' => 'Length', 'type' => 'range', 'range_min' => 1, 'range_max' => 10, 'range_step' => 0.5, 'unit' => 'm', 'unit_price_delta' => 30, 'unit_cost' => 12, 'options' => []],
        ['code' => 'warranty', 'name' => 'Warranty', 'type' => 'boolean', 'options' => [
            ['code' => 'WARR', 'name' => 'Extended warranty', 'price_delta' => 50, 'cost' => 20, 'price_multiplier' => 1.1],
        ]],
        ['code' => 'support', 'name' => 'Support', 'type' => 'single', 'options' => [
            ['code' => 'BASIC', 'name' => 'Basic support', 'is_default' => true],
            ['code' => 'PREMIUM', 'name' => 'Premium support', 'price_delta' => 40, 'cost' => 10],
        ]],
    ];

    foreach ($definition as $group) {
        $groupOptions = $group['options'];
        unset($group['options']);
        $created = test()->postJson("/api/v1/configurator/models/{$model['id']}/groups", $group)->assertCreated()->json('data');
        $groups[$group['code']] = $created['id'];

        foreach ($groupOptions as $option) {
            $options[$option['code']] = test()->postJson("/api/v1/configurator/groups/{$created['id']}/options", $option)->assertCreated()->json('data.id');
        }
    }

    $rules = [
        ['name' => 'GPS needs the warranty', 'type' => 'requires', 'conditions' => [['type' => 'option', 'option' => 'GPS']], 'target_options' => ['WARR']],
        ['name' => 'Large has the radio built in', 'type' => 'excludes', 'conditions' => [['type' => 'option', 'option' => 'L']], 'target_options' => ['RADIO']],
        ['name' => 'Bundle discount', 'type' => 'price_rule', 'match' => 'all', 'conditions' => [['type' => 'option', 'option' => 'GPS'], ['type' => 'range', 'group' => 'length', 'operator' => 'gt', 'value' => 3]], 'price_effect_type' => 'amount', 'price_effect_value' => -50],
        ['name' => 'Large defaults to premium support', 'type' => 'default', 'conditions' => [['type' => 'option', 'option' => 'L']], 'target_options' => ['PREMIUM']],
    ];

    foreach ($rules as $rule) {
        test()->postJson("/api/v1/configurator/models/{$model['id']}/rules", $rule)->assertCreated();
    }

    return ['id' => $model['id'], 'groups' => $groups, 'options' => $options];
}

/**
 * @return array<string, mixed>
 */
function configuratorTruckSelection(): array
{
    return [
        ['group' => 'size', 'options' => ['M']],
        ['group' => 'extras', 'options' => ['GPS']],
        ['group' => 'length', 'value' => 4],
        ['group' => 'warranty', 'options' => ['WARR']],
    ];
}

test('a model is authored with groups, options and rules, refused when its rules loop or conflict, and versioned once published', function (): void {
    configuratorActAs($this->admin, $this->tenant);
    $model = configuratorBuildModel();

    $this->postJson("/api/v1/configurator/models/{$model['id']}/rules", ['name' => 'Warranty needs GPS', 'type' => 'requires', 'conditions' => [['type' => 'option', 'option' => 'WARR']], 'target_options' => ['GPS']])
        ->assertUnprocessable()->assertJsonPath('errors.conditions.0', fn (string $message): bool => str_contains($message, 'cycle'));
    $this->postJson("/api/v1/configurator/models/{$model['id']}/rules", ['name' => 'Large needs the radio', 'type' => 'requires', 'conditions' => [['type' => 'option', 'option' => 'L']], 'target_options' => ['RADIO']])
        ->assertUnprocessable()->assertJsonPath('errors.conditions.0', fn (string $message): bool => str_contains($message, 'both require and exclude'));
    $this->postJson("/api/v1/configurator/models/{$model['id']}/rules", ['name' => 'Self', 'type' => 'excludes', 'conditions' => [['type' => 'option', 'option' => 'GPS']], 'target_options' => ['GPS']])->assertUnprocessable();
    $this->postJson("/api/v1/configurator/models/{$model['id']}/rules", ['name' => 'Ghost', 'type' => 'requires', 'conditions' => [['type' => 'option', 'option' => 'GPS']], 'target_options' => ['NOPE']])->assertUnprocessable();
    $this->postJson("/api/v1/configurator/models/{$model['id']}/rules", ['name' => 'Missing targets', 'type' => 'excludes', 'conditions' => []])->assertUnprocessable()->assertJsonValidationErrors('target_options');
    $this->getJson("/api/v1/configurator/models/{$model['id']}/rule-check")->assertOk()->assertJsonPath('data.valid', true);

    $this->deleteJson("/api/v1/configurator/options/{$model['options']['GPS']}")->assertUnprocessable();
    $this->postJson("/api/v1/configurator/groups/{$model['groups']['warranty']}/options", ['code' => 'WARR2', 'name' => 'Second'])->assertUnprocessable();
    $this->postJson("/api/v1/configurator/groups/{$model['groups']['length']}/options", ['code' => 'X', 'name' => 'X'])->assertUnprocessable();
    $this->postJson("/api/v1/configurator/groups/{$model['groups']['size']}/options", ['code' => 'S', 'name' => 'Duplicate code'])->assertUnprocessable()->assertJsonValidationErrors('code');

    $emptyId = $this->postJson('/api/v1/configurator/models', ['code' => 'EMPTY', 'name' => 'Empty'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/configurator/models/{$emptyId}/publish")->assertUnprocessable()->assertJsonValidationErrors('status');

    $published = $this->postJson("/api/v1/configurator/models/{$model['id']}/publish")->assertOk()->assertJsonPath('data.status', 'published')->json('data');
    $this->putJson("/api/v1/configurator/options/{$model['options']['M']}", ['price_delta' => 120])->assertOk();
    $this->getJson("/api/v1/configurator/models/{$model['id']}")->assertOk()->assertJsonPath('data.version', $published['version'] + 1)->assertJsonCount(5, 'data.groups')->assertJsonCount(4, 'data.rules');
    $this->postJson("/api/v1/configurator/models/{$model['id']}/publish")->assertUnprocessable();

    $this->postJson("/api/v1/configurator/models/{$model['id']}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
    $this->putJson("/api/v1/configurator/options/{$model['options']['M']}", ['price_delta' => 130])->assertUnprocessable();
    $this->deleteJson("/api/v1/configurator/models/{$emptyId}")->assertNoContent();
});

test('a selection is validated against groups and rules, completed with defaults and priced in exact decimals', function (): void {
    configuratorActAs($this->admin, $this->tenant);
    $model = configuratorBuildModel();
    $this->postJson("/api/v1/configurator/models/{$model['id']}/publish")->assertOk();

    $evaluation = $this->postJson("/api/v1/configurator/models/{$model['id']}/evaluate", ['selections' => configuratorTruckSelection(), 'apply_defaults' => true])->assertOk()->json('data');
    expect($evaluation['valid'])->toBeTrue()
        ->and($evaluation['pricing']['unit_list_price'])->toBe('1562.0000')
        ->and($evaluation['pricing']['total_net'])->toBe('1562.00')
        ->and($evaluation['pricing']['total_tax'])->toBe('343.64')
        ->and($evaluation['pricing']['total_gross'])->toBe('1905.64')
        ->and($evaluation['pricing']['total_cost'])->toBe('848.00')
        ->and($evaluation['pricing']['margin_percent'])->toBe('45.71')
        ->and($evaluation['pricing']['requires_approval'])->toBeFalse()
        ->and($evaluation['pricing']['lines'])->toHaveCount(7)
        ->and(array_sum(array_map(static fn (array $line): float => (float) $line['unit_price'], $evaluation['pricing']['lines'])))->toBe(1562.0)
        ->and(collect($evaluation['selections'])->firstWhere('group', 'support')['options'])->toBe(['BASIC']);

    $answers = $this->postJson("/api/v1/configurator/models/{$model['id']}/evaluate", ['selections' => [], 'apply_defaults' => false])->assertOk()->json('data');
    expect($answers['valid'])->toBeFalse()->and(array_column($answers['violations'], 'code'))->toBe(['required_group_missing']);

    $defaults = $this->postJson("/api/v1/configurator/models/{$model['id']}/evaluate", ['selections' => [['group' => 'size', 'options' => ['L']]]])->assertOk()->json('data');
    expect($defaults['valid'])->toBeTrue()
        ->and(collect($defaults['selections'])->firstWhere('group', 'support')['options'])->toBe(['PREMIUM']);

    $codes = fn (array $selections): array => array_column($this->postJson("/api/v1/configurator/models/{$model['id']}/evaluate", ['selections' => $selections, 'apply_defaults' => false])->json('data.violations'), 'code');
    expect($codes([['group' => 'size', 'options' => ['L']], ['group' => 'extras', 'options' => ['RADIO']]]))->toBe(['excludes'])
        ->and($codes([['group' => 'size', 'options' => ['S']], ['group' => 'extras', 'options' => ['GPS']]]))->toBe(['requires'])
        ->and($codes([['group' => 'size', 'options' => ['S']], ['group' => 'length', 'value' => 11]]))->toBe(['range_out_of_bounds'])
        ->and($codes([['group' => 'size', 'options' => ['S']], ['group' => 'length', 'value' => 3.2]]))->toBe(['range_step_mismatch'])
        ->and($codes([['group' => 'size', 'options' => ['S', 'M']]]))->toBe(['single_choice_exceeded'])
        ->and($codes([['group' => 'size', 'options' => ['S']], ['group' => 'extras', 'options' => ['NOPE']]]))->toBe(['unknown_option'])
        ->and($codes([['group' => 'size', 'options' => ['S']], ['group' => 'ghost', 'options' => ['S']]]))->toBe(['unknown_group']);

    $volume = $this->postJson("/api/v1/configurator/models/{$model['id']}/evaluate", ['selections' => configuratorTruckSelection(), 'quantity' => 10])->assertOk()->json('data.pricing');
    expect($volume['volume_discount_percent'])->toBe('5.00')
        ->and($volume['total_net'])->toBe('14839.00')
        ->and($volume['total_cost'])->toBe('8480.00')
        ->and($volume['margin_percent'])->toBe('42.85');
});

test('the margin guard asks for an approval, and an approved configuration becomes a quote that later price changes never alter', function (): void {
    configuratorActAs($this->admin, $this->tenant);
    $model = configuratorBuildModel();
    $this->postJson("/api/v1/configurator/models/{$model['id']}/publish")->assertOk();

    configuratorActAs($this->sales, $this->tenant);
    $configuration = $this->postJson('/api/v1/configurator/configurations', [
        'model_id' => $model['id'], 'selections' => configuratorTruckSelection(), 'contact_id' => $this->contact->id, 'discount_percent' => 40,
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.requires_approval', true)->assertJsonPath('data.total_net', '937.20')->assertJsonPath('data.margin_percent', '9.52')->json('data');
    expect($configuration['number'])->toMatch('/^CFG-\d{4}-0001$/');

    $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/request-approval")->assertUnprocessable();
    $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/validate")->assertOk()->assertJsonPath('data.valid', true);
    $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/convert-to-quote")->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/request-approval")->assertOk()->assertJsonPath('data.status', 'pending_approval');
    $this->putJson("/api/v1/configurator/configurations/{$configuration['id']}", ['quantity' => 2])->assertUnprocessable();
    $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/approve")->assertForbidden();

    configuratorActAs($this->manager, $this->tenant);
    $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/reject")->assertUnprocessable()->assertJsonValidationErrors('decision_notes');
    $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/approve", ['decision_notes' => 'Strategic customer'])->assertOk()->assertJsonPath('data.status', 'approved');

    configuratorActAs($this->sales, $this->tenant);
    $converted = $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/convert-to-quote", ['notes' => 'Delivery in 8 weeks'])->assertOk()->assertJsonPath('data.status', 'converted')->json('data');
    expect($converted['quote_id'])->not->toBeNull()->and($converted['quote_number'])->not->toBeNull();

    app(TenantContext::class)->setTenant($this->tenant);
    $quote = Quote::query()->with('items')->findOrFail($converted['quote_id']);
    expect($quote->items)->toHaveCount(7)
        ->and($quote->total_net)->toBe('937.20')
        ->and($quote->total_tax)->toBe('206.18')
        ->and($quote->contact_id)->toBe($this->contact->id)
        ->and($quote->custom_text)->toContain('Electric truck')->toContain('Size: Medium')->toContain('Length: 4 m')
        ->and($quote->items->pluck('discount_percentage')->unique()->all())->toBe(['40.00']);

    $versionAtQuote = ProductConfiguration::query()->findOrFail($converted['id'])->snapshot['model']['version'];
    configuratorActAs($this->admin, $this->tenant);
    $this->putJson("/api/v1/configurator/options/{$model['options']['M']}", ['price_delta' => 999])->assertOk();
    $this->putJson("/api/v1/configurator/models/{$model['id']}", ['base_price' => 5000])->assertOk();

    $detail = $this->getJson("/api/v1/configurator/configurations/{$converted['id']}")->assertOk()->json('data');
    expect($detail['snapshot']['model']['version'])->toBe($versionAtQuote)
        ->and($detail['snapshot']['model']['base_price'])->toBe('1000.0000')
        ->and($detail['snapshot']['prices']['total_net'])->toBe('937.20')
        ->and($detail['snapshot']['rules_hash'])->toHaveLength(64)
        ->and($detail['snapshot']['options'])->toHaveCount(4)
        ->and(Quote::query()->findOrFail($converted['quote_id'])->total_net)->toBe('937.20');

    $this->putJson("/api/v1/configurator/configurations/{$converted['id']}", ['quantity' => 3])->assertUnprocessable();
    $this->postJson("/api/v1/configurator/configurations/{$converted['id']}/convert-to-quote")->assertUnprocessable();
    $this->deleteJson("/api/v1/configurator/configurations/{$converted['id']}")->assertUnprocessable();
});

test('a rejected discount goes back to draft when the author changes the configuration, and a fine margin needs no approval', function (): void {
    configuratorActAs($this->admin, $this->tenant);
    $model = configuratorBuildModel();
    $this->postJson("/api/v1/configurator/models/{$model['id']}/publish")->assertOk();

    configuratorActAs($this->sales, $this->tenant);
    $configuration = $this->postJson('/api/v1/configurator/configurations', ['model_id' => $model['id'], 'selections' => configuratorTruckSelection(), 'contact_id' => $this->contact->id, 'discount_percent' => 40])->assertCreated()->json('data');
    $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/validate")->assertOk();
    $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/request-approval")->assertOk();

    configuratorActAs($this->manager, $this->tenant);
    $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/reject", ['decision_notes' => 'Too low'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_notes', 'Too low');

    configuratorActAs($this->sales, $this->tenant);
    $this->putJson("/api/v1/configurator/configurations/{$configuration['id']}", ['discount_percent' => 5])->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.requires_approval', false)->assertJsonPath('data.decision_notes', null);
    $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/validate")->assertOk()->assertJsonPath('data.valid', true);
    $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/request-approval")->assertUnprocessable();
    $this->postJson("/api/v1/configurator/configurations/{$configuration['id']}/convert-to-quote")->assertOk()->assertJsonPath('data.status', 'converted');

    $invalid = $this->postJson('/api/v1/configurator/configurations', ['model_id' => $model['id'], 'selections' => [['group' => 'size', 'options' => ['L']], ['group' => 'extras', 'options' => ['RADIO']]]])->assertCreated()->json('data');
    $this->postJson("/api/v1/configurator/configurations/{$invalid['id']}/validate")->assertOk()->assertJsonPath('data.valid', false);
    $this->getJson("/api/v1/configurator/configurations/{$invalid['id']}")->assertJsonPath('data.status', 'draft');
    $this->postJson("/api/v1/configurator/configurations/{$invalid['id']}/convert-to-quote")->assertUnprocessable();
    $this->deleteJson("/api/v1/configurator/configurations/{$invalid['id']}")->assertNoContent();
});

test('the base price of a model and of its options can come from the price lists of the company', function (): void {
    configuratorActAs($this->admin, $this->tenant);
    $product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'CHS-1', 'name' => 'Chassis', 'sale_price' => 400, 'tax_rate' => 22]);
    $priceList = $this->postJson('/api/v1/catalog/price-lists', ['name' => 'Dealers', 'currency' => 'EUR', 'items' => [
        ['product_id' => $product->id, 'price' => 300],
        ['product_id' => $product->id, 'price' => 250, 'min_quantity' => 10],
    ]])->assertCreated()->json('data.id');

    $model = $this->postJson('/api/v1/configurator/models', ['code' => 'LIST-1', 'name' => 'Listed', 'base_product_id' => $product->id, 'base_price_from_product' => true, 'base_price' => 999, 'base_cost' => 100])->assertCreated()->json('data');
    $group = $this->postJson("/api/v1/configurator/models/{$model['id']}/groups", ['code' => 'frame', 'name' => 'Frame', 'type' => 'single'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/configurator/groups/{$group}/options", ['code' => 'FRAME', 'name' => 'Frame', 'product_id' => $product->id, 'price_from_product' => true, 'price_delta' => 1])->assertCreated();
    $selection = ['selections' => [['group' => 'frame', 'options' => ['FRAME']]], 'price_list_id' => $priceList];

    $single = $this->postJson("/api/v1/configurator/models/{$model['id']}/evaluate", $selection)->assertOk()->json('data.pricing');
    expect(array_column($single['lines'], 'unit_price'))->toBe(['300.0000', '300.0000'])->and($single['unit_list_price'])->toBe('600.0000');

    $bulk = $this->postJson("/api/v1/configurator/models/{$model['id']}/evaluate", $selection + ['quantity' => 10])->assertOk()->json('data.pricing');
    expect(array_column($bulk['lines'], 'unit_price'))->toBe(['250.0000', '250.0000'])->and($bulk['total_net'])->toBe('5000.00');
});

test('roles and tenants: members read, sales configure, managers define and approve, other companies see nothing', function (): void {
    configuratorActAs($this->admin, $this->tenant);
    $model = configuratorBuildModel();
    $this->postJson("/api/v1/configurator/models/{$model['id']}/publish")->assertOk();

    $member = configuratorMemberOf($this->tenant, 'member');
    configuratorActAs($member, $this->tenant);
    $this->getJson('/api/v1/configurator/models')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/configurator/models/{$model['id']}")->assertOk();
    $this->postJson('/api/v1/configurator/models', ['code' => 'NO', 'name' => 'No'])->assertForbidden();

    configuratorActAs($this->sales, $this->tenant);
    $this->postJson('/api/v1/configurator/models', ['code' => 'NO', 'name' => 'No'])->assertForbidden();
    $this->postJson("/api/v1/configurator/models/{$model['id']}/publish")->assertForbidden();
    $this->postJson("/api/v1/configurator/models/{$model['id']}/groups", ['code' => 'x', 'name' => 'X', 'type' => 'single'])->assertForbidden();
    $this->postJson('/api/v1/configurator/configurations', ['model_id' => $model['id'], 'selections' => configuratorTruckSelection()])->assertCreated();

    $granted = configuratorMemberOf($this->tenant, 'sales');
    $granted->tenants()->updateExistingPivot($this->tenant->id, ['permissions' => json_encode(['configurator.manage'])]);
    configuratorActAs($granted, $this->tenant);
    $this->postJson('/api/v1/configurator/models', ['code' => 'YES', 'name' => 'Yes'])->assertCreated();

    $otherTenant = Tenant::create(['name' => 'Altra Srl', 'legal_name' => 'Altra Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    configuratorActAs(configuratorMemberOf($otherTenant, 'admin'), $otherTenant);
    $this->getJson("/api/v1/configurator/models/{$model['id']}")->assertNotFound();
    $this->getJson('/api/v1/configurator/models')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/configurator/configurations', ['model_id' => $model['id'], 'selections' => []])->assertUnprocessable()->assertJsonValidationErrors('model_id');
});
