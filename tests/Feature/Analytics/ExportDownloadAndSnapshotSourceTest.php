<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Mail\ScheduledReportMail;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportRun;
use Modules\Analytics\Models\ExportSchedule;

require_once __DIR__.'/BiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-15 10:00:00');
    Mail::fake();
    Storage::fake((string) config('filesystems.private_disk', config('filesystems.default', 'local')));
    $this->tenant = biTenant('Alfa');
    $this->user = biActAs($this->tenant);
    biInvoice($this->tenant, biCustomer($this->tenant, 'Rossi'), '2027-03-05', '150.00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Runs a daily schedule of a new report owned by the user and returns its run and signed link.
 *
 * @return array{0: ExportRun, 1: string, 2: User}
 */
function biStoredExport(object $test, ?string $locale = null): array
{
    $owner = $test->user;

    if ($locale !== null) {
        $owner = biUser($test->tenant, 'admin');
        $owner->update(['locale' => $locale]);
    }

    $report = DesignedReport::factory()->create(['tenant_id' => $test->tenant->id, 'user_id' => $owner->id, 'dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount'])]);
    $schedule = ExportSchedule::factory()->create(['tenant_id' => $test->tenant->id, 'user_id' => $owner->id, 'designed_report_id' => $report->id, 'recipients' => [$owner->email], 'next_run_on' => '2027-03-16']);
    Carbon::setTestNow('2027-03-16 06:00:00');
    Artisan::call('analytics:run-scheduled-exports', ['--tenant' => [$test->tenant->id]]);
    Carbon::setTestNow('2027-03-15 10:00:00');
    $link = null;
    Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $mail) use (&$link, $owner): bool {
        if ($mail->hasTo($owner->email)) {
            $link = $mail->link;
        }

        return true;
    });

    return [ExportRun::query()->where('schedule_id', $schedule->id)->sole(), (string) $link, $owner];
}

test('the signed download rejects unsigned, tampered, expired and cross-company requests and serves only the right file', function (): void {
    [$run, $link] = biStoredExport($this);
    $other = biTenant('Beta');
    $foreignRun = ExportRun::query()->withoutGlobalScopes()->create([
        'tenant_id' => $other->id, 'user_id' => $this->user->id, 'dataset' => 'sales', 'format' => 'csv', 'row_count' => 1, 'file_name' => 'beta.csv',
        'disk' => $run->disk, 'path' => 'tenants/'.$other->id.'/analytics-exports/beta.csv', 'expires_at' => now()->addDays(3),
    ]);
    Storage::disk((string) $run->disk)->put((string) $foreignRun->path, "secret of beta\r\n");
    app('auth')->forgetGuards();

    // The valid link works without a token and without a company header.
    $this->withHeaders(['X-Tenant-Id' => ''])->get($link)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    // Unsigned, and signed with a signature that is not for this URL.
    $this->get(route('analytics.exports.download', ['run' => $run->id]))->assertForbidden();
    $this->get(str_replace('signature=', 'signature=0', $link))->assertForbidden();
    $this->get(preg_replace('/signature=[0-9a-f]{64}/', 'signature='.str_repeat('a', 64), $link))->assertForbidden();

    // Tampered parameters: a later expiry, another run of the same signature, another company's run.
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
    $this->get(route('analytics.exports.download', ['run' => $run->id, 'expires' => $query['expires'] + 86400, 'signature' => $query['signature']]))->assertForbidden();
    $this->get(route('analytics.exports.download', ['run' => $foreignRun->id, 'expires' => $query['expires'], 'signature' => $query['signature']]))->assertForbidden();
    $this->get(str_replace($run->id, (string) Str::uuid(), $link))->assertForbidden();

    // A link legitimately signed for the other company's file cannot be used to read it from this company's context.
    $foreignLink = URL::temporarySignedRoute('analytics.exports.download', now()->addDay(), ['run' => $foreignRun->id]);
    $this->withHeaders(['X-Tenant-Id' => $this->tenant->id])->get($foreignLink)->assertForbidden();
    $this->withHeaders(['X-Tenant-Id' => $other->id])->get($link)->assertForbidden();
    $this->withHeaders(['X-Tenant-Id' => $this->tenant->id])->get($link)->assertOk();
    expect($this->withHeaders(['X-Tenant-Id' => ''])->get($foreignLink)->assertOk()->streamedContent())->toBe("secret of beta\r\n");

    // Expired link, unknown run, other methods.
    $this->postJson($link)->assertStatus(405);
    $this->get(URL::temporarySignedRoute('analytics.exports.download', now()->addDay(), ['run' => (string) Str::uuid()]))->assertNotFound();
    Carbon::setTestNow('2027-03-30 10:00:00');
    $this->get($link)->assertForbidden();

    // A file already purged is gone even with a fresh signature.
    $run->update(['path' => null]);
    $this->get(URL::temporarySignedRoute('analytics.exports.download', now()->addDay(), ['run' => $run->id]))->assertNotFound();
    $run->update(['path' => 'tenants/x/y.csv', 'expires_at' => now()->subDay()]);
    $this->get(URL::temporarySignedRoute('analytics.exports.download', now()->addDay(), ['run' => $run->id]))->assertNotFound();
});

test('the e-mail of a scheduled export is written in the language of the owner', function (): void {
    $expected = [
        'en' => 'is ready',
        'it' => 'è pronto',
        'de' => 'ist bereit',
        'fr' => 'est prêt',
    ];
    $owners = [];

    foreach ($expected as $locale => $phrase) {
        $owners[$locale] = biStoredExport($this, $locale)[2];
    }

    foreach ($expected as $locale => $phrase) {
        Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail): bool => $mail->hasTo($owners[$locale]->email) && $mail->locale === $locale);
        Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $mail) use ($owners, $locale, $phrase): bool {
            if (! $mail->hasTo($owners[$locale]->email)) {
                return false;
            }

            $mail->assertSeeInHtml($phrase);

            return true;
        });
    }
});

test('the kpi endpoints keep their default shape and can opt in to the daily snapshots', function (): void {
    $default = $this->getJson('/api/v1/analytics/kpis/sales?from=2027-03-01&to=2027-03-15')->assertOk()->json('data');
    expect(array_keys($default))->toBe(['key', 'label', 'unit', 'kind', 'available', 'reason', 'value', 'details', 'comparison', 'change', 'change_percent']);
    $index = $this->getJson('/api/v1/analytics/kpis?kpis[]=sales')->assertOk()->json('data');
    expect(array_keys($index))->toBe(['period', 'comparison_period', 'kpis'])->and($index['kpis'][0])->not->toHaveKey('source');

    // Without a snapshot yet the opt-in answers with the live calculation, and says so.
    $live = $this->getJson('/api/v1/analytics/kpis/sales?source=snapshot')->assertOk()->json('data');
    expect($live['source'])->toBe('live')->and($live['snapshot_date'])->toBeNull()->and($live['value'])->toBe('150.00');

    biInvoice($this->tenant, biCustomer($this->tenant, 'Verdi'), '2027-03-10', '50.00');
    Artisan::call('analytics:refresh-kpi-snapshots', ['--tenant' => [$this->tenant->id], '--date' => '2027-03-14']);
    biInvoice($this->tenant, biCustomer($this->tenant, 'Neri'), '2027-03-14', '1000.00');

    $snapshot = $this->getJson('/api/v1/analytics/kpis/sales?source=snapshot')->assertOk()->json('data');
    expect($snapshot['source'])->toBe('snapshot')
        ->and($snapshot['snapshot_date'])->toBe('2027-03-14')
        ->and($snapshot['value'])->toBe('200.00')
        ->and(array_keys($default))->toEqual(array_slice(array_keys($snapshot), 0, count($default)));
    expect($this->getJson('/api/v1/analytics/kpis/sales?from=2027-03-01&to=2027-03-15')->json('data.value'))->toBe('1200.00');

    $all = $this->getJson('/api/v1/analytics/kpis?source=snapshot')->assertOk()->json('data');
    $bySource = collect($all['kpis'])->pluck('source', 'key')->all();
    expect($all['period'])->toBe(['from' => '2027-03-01', 'to' => '2027-03-14'])
        ->and($bySource['sales'])->toBe('snapshot')
        ->and($bySource['stock-value'])->toBe('snapshot')
        ->and($bySource['hr-headcount'])->toBe('live')
        ->and($bySource['project-margin'])->toBe('live');

    // An earlier day is served by the latest snapshot up to it.
    expect($this->getJson('/api/v1/analytics/kpis/sales?source=snapshot&to=2027-03-13')->json('data.source'))->toBe('live');

    // Comparison with the snapshot of the same day one year earlier.
    biInvoice($this->tenant, biCustomer($this->tenant, 'Vecchio'), '2026-03-05', '100.00');
    Artisan::call('analytics:refresh-kpi-snapshots', ['--tenant' => [$this->tenant->id], '--date' => '2026-03-14']);
    $compared = $this->getJson('/api/v1/analytics/kpis/sales?source=snapshot&compare=previous_year')->assertOk()->json('data');
    expect($compared['comparison']['value'])->toBe('100.00')->and($compared['change'])->toBe('100.00')->and($compared['change_percent'])->toBe('100.00');
    $aging = $this->getJson('/api/v1/analytics/kpis/stock-value?source=snapshot&compare=previous_year')->json('data');
    expect($aging['reason'])->toBe('snapshot_not_comparable');

    $this->getJson('/api/v1/analytics/kpis?source=bogus')->assertUnprocessable()->assertJsonValidationErrors(['source']);

    // Another company has its own snapshots; a role that cannot read a module gets that indicator as unavailable.
    $other = biTenant('Beta');
    biActAs($other);
    expect($this->getJson('/api/v1/analytics/kpis/sales?source=snapshot')->json('data.source'))->toBe('live');
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
    Sanctum::actingAs(biUser($this->tenant, 'accountant_readonly'));
    expect($this->getJson('/api/v1/analytics/kpis/hr-headcount?source=snapshot')->assertOk()->json('data.available'))->toBeFalse();
});
