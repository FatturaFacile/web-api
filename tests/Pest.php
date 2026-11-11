<?php

declare(strict_types=1);
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you build a more flexible test suite.
|
*/

/**
 * An open fiscal calendar, so that documents of that year can reach the ledger.
 */
function fiscalCalendar(Tenant $tenant, int $year = 2027): void
{
    $fiscalYear = FiscalYear::create([
        'tenant_id' => (string) $tenant->id,
        'year_code' => "FY{$year}",
        'start_date' => "{$year}-01-01",
        'end_date' => "{$year}-12-31",
        'status' => 'open',
    ]);

    foreach (range(1, 12) as $month) {
        $first = CarbonImmutable::create($year, $month, 1);

        FiscalPeriod::create([
            'tenant_id' => (string) $tenant->id,
            'fiscal_year_id' => $fiscalYear->id,
            'period_number' => $month,
            'period_name' => $first->format('F Y'),
            'start_date' => $first->toDateString(),
            'end_date' => $first->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);
    }
}

/**
 * Headers of a request that comes from the first-party SPA (https origin listed in the Sanctum stateful domains): the
 * only kind of request that can open the cookie session.
 *
 * @return array<string, string>
 */
function spaHeaders(): array
{
    $origin = rtrim((string) config('app.frontend_url'), '/');

    return ['Origin' => $origin, 'Referer' => $origin.'/'];
}
