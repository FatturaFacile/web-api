<?php

declare(strict_types=1);

use App\Contracts\ExchangeRateProviderInterface;
use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Exchange rates are central data read by every tenant: the sync writes them for all, so only a platform administrator
 * may call it (found by the tenant isolation verification: any owner could write global rows).
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->app->instance(ExchangeRateProviderInterface::class, new class implements ExchangeRateProviderInterface
    {
        public function getRates(string $baseCurrency = 'EUR'): array
        {
            return [['source' => 'EUR', 'target' => 'CHF', 'rate' => 0.9400]];
        }
    });
});

function rateSyncUser(Tenant $tenant, string $role, bool $platformAdmin): User
{
    $user = User::create(['name' => 'U '.Str::random(4), 'email' => Str::random(12).'@example.test', 'password' => bcrypt(Str::password(24)), 'locale' => 'it']);
    $user->forceFill(['is_platform_admin' => $platformAdmin])->save();
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

test('an owner of a tenant cannot write the central exchange rates', function (): void {
    Sanctum::actingAs(rateSyncUser($this->tenant, 'owner', false));

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/settings/exchange-rates/sync')->assertForbidden();

    expect(ExchangeRate::query()->count())->toBe(0);
});

test('a platform administrator writes the rates, once a day and pair', function (): void {
    Sanctum::actingAs(rateSyncUser($this->tenant, 'owner', true));
    $request = fn () => $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/settings/exchange-rates/sync');

    $request()->assertOk()->assertJsonPath('data.synced_pairs', 1);
    $request()->assertOk();

    expect(ExchangeRate::query()->where('source', 'EUR')->where('target', 'CHF')->count())->toBe(1)
        ->and((float) ExchangeRate::query()->where('source', 'EUR')->where('target', 'CHF')->value('rate'))->toBe(0.94);
});

test('an unauthenticated call is refused', function (): void {
    $this->postJson('/api/v1/settings/exchange-rates/sync')->assertUnauthorized();
});
