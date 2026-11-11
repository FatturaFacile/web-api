<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TenantModelRegistry;
use Tests\Support\TenantRecordSeeder;

uses(RefreshDatabase::class);

/**
 * Registry driven tenant isolation through the API.
 *
 * A row of every tenant scoped model is created in tenant A. The API routes that end with an id (`.../{id}`) are
 * matched to the models by the resource segment of the URL (the table name, or the plural kebab-case class name). A
 * member of tenant B, with the owner role, then calls every matched route for the row of tenant A: the collection must
 * not contain the id, and show, update and delete must answer with a client error and leave the row untouched.
 *
 * Routes whose resource segment matches no model are not probed here; each module keeps its own isolation tests. The
 * coverage guard fails when fewer routes than the floor are probed.
 */

/**
 * @return array<string, list<class-string<Model>>> kebab-case resource segment => models
 */
function isolationSegments(): array
{
    $segments = [];

    foreach (TenantModelRegistry::models() as $entry) {
        if (! $entry['scoped'] || ! $entry['hasTenantColumn']) {
            continue;
        }

        $table = str_replace('_', '-', $entry['table']);
        $class = Str::plural(Str::kebab(class_basename($entry['class'])));

        foreach (array_unique([$table, $class]) as $segment) {
            $segments[$segment][] = $entry['class'];
        }

        // Module prefixes: `hosp_orders` is served as `orders`.
        if (str_contains($table, '-')) {
            $segments[Str::after($table, '-')][] = $entry['class'];
        }
    }

    return $segments;
}

test('a member of another tenant cannot list, read, change or delete the rows of a tenant through the API', function (): void {
    $tenantA = TenantRecordSeeder::tenant('Alfa');
    $tenantB = TenantRecordSeeder::tenant('Beta');
    $userB = User::create([
        'name' => 'Owner Beta',
        'email' => Str::random(12).'@example.test',
        'password' => bcrypt(Str::password(24)),
        'locale' => 'it',
    ]);
    $userB->tenants()->attach($tenantB->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    $segments = isolationSegments();
    $probes = [];

    foreach (Route::getRoutes() as $route) {
        /** @var LaravelRoute $route */
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/') || ! preg_match('~^(?<collection>.+)/\{(?<param>[^}/]+)\}$~', $uri, $match)) {
            continue;
        }

        if (str_contains($match['collection'], '{')) {
            continue;
        }

        $resource = Str::afterLast($match['collection'], '/');

        foreach ($segments[$resource] ?? [] as $modelClass) {
            $probes[$modelClass][] = ['route' => $route, 'collection' => $match['collection'], 'param' => $match['param']];
        }
    }

    $seeded = [];

    foreach (array_keys($probes) as $modelClass) {
        $entry = collect(TenantModelRegistry::models())->firstWhere('class', $modelClass);
        $key = TenantRecordSeeder::seed($modelClass, $tenantA, (bool) $entry['hasFactory']);

        if ($key !== null) {
            $seeded[$modelClass] = $key;
        }
    }

    $failures = [];
    $probed = 0;
    $statuses = [];
    $serverErrors = [];
    Sanctum::actingAs($userB);
    test()->withoutMiddleware(ThrottleRequests::class);

    foreach ($seeded as $modelClass => $key) {
        $entry = collect(TenantModelRegistry::models())->firstWhere('class', $modelClass);
        $table = $entry['table'];
        $before = (array) DB::table($table)->where((new $modelClass)->getKeyName(), $key)->first();

        foreach ($probes[$modelClass] as $probe) {
            /** @var LaravelRoute $route */
            $route = $probe['route'];
            $url = '/'.$probe['collection'].'/'.$key;
            $headers = ['X-Tenant-Id' => $tenantB->id, 'Accept' => 'application/json'];

            foreach ($route->methods() as $method) {
                if (! in_array($method, ['GET', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }

                $probed++;
                app(TenantContext::class)->clear();
                $response = test()->withHeaders($headers)->json($method, $url, []);
                $status = $response->getStatusCode();
                $statuses[$status] = ($statuses[$status] ?? 0) + 1;

                if ($status >= 500) {
                    $serverErrors[] = "{$method} {$url} answered {$status} ({$modelClass}): ".Str::limit((string) ($response->json('message') ?? ''), 120);
                }

                $baseline = test()->withHeaders($headers)->json($method, '/'.$probe['collection'].'/'.Str::uuid7(), [])->getStatusCode();

                if ($status < 400 && $baseline >= 400) {
                    $failures[] = "{$method} {$url} answered {$status} to another tenant, an unknown id answers {$baseline} ({$modelClass})";
                }

                if (str_contains((string) $response->getContent(), $key) && $status < 400) {
                    $failures[] = "{$method} {$url} returned the id of another tenant ({$modelClass})";
                }
            }

            $listing = test()->withHeaders($headers)->getJson('/'.$probe['collection']);

            if ($listing->getStatusCode() < 400 && str_contains((string) $listing->getContent(), $key)) {
                $failures[] = "GET /{$probe['collection']} lists the row of another tenant ({$modelClass})";
            }
        }

        $after = (array) DB::table($table)->where((new $modelClass)->getKeyName(), $key)->first();

        if ($after !== $before) {
            $failures[] = "{$modelClass}: the row was changed through the API by another tenant";
        }
    }

    file_put_contents(sys_get_temp_dir().'/isolation_http.json', json_encode(['probed' => $probed, 'models' => count($seeded), 'statuses' => $statuses, 'serverErrors' => $serverErrors, 'failures' => array_values(array_unique($failures))], JSON_PRETTY_PRINT));

    expect(array_values(array_unique($failures)))->toBe([]);
    expect($probed)->toBeGreaterThanOrEqual(1);
});
