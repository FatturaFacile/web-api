<?php

declare(strict_types=1);

use App\Services\Security\OutboundUrlGuard;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Analytics\Jobs\ExecuteScheduledReportJob;
use Modules\Analytics\Mail\ScheduledReportMail;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportRun;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Services\ScheduledExportService;
use Modules\Communication\Models\CommunicationChannel;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsFolder;
use Modules\Integrations\Webhooks\WebhookSigner;
use Tests\Support\StaticHostResolver;

require_once __DIR__.'/BiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-15 10:00:00');
    Mail::fake();
    Storage::fake((string) config('filesystems.private_disk', config('filesystems.default', 'local')));
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(new StaticHostResolver));
    $this->tenant = biTenant('Alfa');
    $this->user = biActAs($this->tenant);
    $this->colleague = biUser($this->tenant, 'accountant');
    biInvoice($this->tenant, biCustomer($this->tenant, 'Rossi'), '2027-03-05', '1234.50');
    $this->report = DesignedReport::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'name' => 'Weekly sales', 'dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount'])]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function cronPayload(array $extra = []): array
{
    return [
        'designed_report_id' => test()->report->id,
        'name' => 'Monday digest',
        'cron_expression' => '0 7 * * 1',
        'timezone' => 'Europe/Zurich',
        'locale' => 'it-CH',
        'format' => 'xlsx',
        'recipient_user_ids' => [test()->colleague->id],
        'delivery_channels' => ['email' => true],
        ...$extra,
    ];
}

test('the cron preview validates the expression and shows the next runs on the wall clock of the zone', function (): void {
    $runs = $this->postJson('/api/v1/analytics/export-schedules/cron-preview', ['cron_expression' => '0 7 * * 1', 'timezone' => 'Europe/Zurich'])->assertOk()->json('data.next_runs');
    expect($runs)->toHaveCount(5)->and($runs[0])->toBe('2027-03-22T07:00:00+01:00')->and($runs[2])->toBe('2027-04-05T07:00:00+02:00');

    foreach (['61 * * * *', 'garbage', '* * * * *', '*/3 * * * *'] as $bad) {
        $this->postJson('/api/v1/analytics/export-schedules/cron-preview', ['cron_expression' => $bad])->assertUnprocessable()->assertJsonValidationErrors('cron_expression');
    }

    $this->postJson('/api/v1/analytics/export-schedules/cron-preview', ['cron_expression' => '0 7 * * 1', 'timezone' => 'Mars/Olympus'])->assertUnprocessable();
});

test('a cron schedule stores its next run in utc, its locale, format and channels, and never returns a secret', function (): void {
    $folder = DmsFolder::create(['tenant_id' => $this->tenant->id, 'name' => 'Reports']);
    config(['analytics.reporting.allow_external_recipients' => true]);

    $response = $this->postJson('/api/v1/analytics/export-schedules', cronPayload([
        'external_emails' => ['Accountant@External.example'],
        'delivery_channels' => ['email' => true, 'telegram_chat_ids' => ['-100123456'], 'dms_folder_id' => $folder->id, 'webhook_url' => 'https://hooks.example.com/reports'],
        'sftp' => ['host' => 'sftp.example.com', 'username' => 'reports', 'password' => 'a-secret-password', 'path' => '/in'],
    ]))->assertCreated();

    $schedule = $response->json('data.schedule');
    expect($schedule['frequency'])->toBe('cron')
        ->and($schedule['next_run_at'])->toBe('2027-03-22T06:00:00+00:00')
        ->and($schedule['locale'])->toBe('it-CH')
        ->and($schedule['format'])->toBe('xlsx')
        ->and($schedule['external_emails'])->toBe(['accountant@external.example'])
        ->and($schedule['delivery_channels']['dms_folder_id'])->toBe($folder->id)
        ->and($schedule['sftp'])->toBe(['host' => 'sftp.example.com', 'port' => 22, 'username' => 'reports', 'path' => '/in', 'secret_set' => true])
        ->and($schedule['webhook_secret_set'])->toBeTrue();

    // The webhook secret is shown once, at creation; the password never.
    $secret = $response->json('data.webhook_secret');
    expect($secret)->toStartWith('whsec_')->and($response->getContent())->not->toContain('a-secret-password');
    $stored = ExportSchedule::query()->findOrFail($schedule['id']);
    expect($stored->secret_config_encrypted['webhook_secret'])->toBe($secret)->and(DB::table('analytics_export_schedules')->where('id', $stored->id)->value('secret_config_encrypted'))->not->toContain('a-secret-password');
    $this->getJson("/api/v1/analytics/export-schedules/{$stored->id}")->assertOk()->assertJsonMissingPath('data.webhook_secret')->assertJsonPath('data.webhook_secret_set', true);

    // Changing it keeps the secrets that were not sent again and recomputes the next run.
    $this->putJson("/api/v1/analytics/export-schedules/{$stored->id}", cronPayload(['cron_expression' => '30 8 * * *', 'delivery_channels' => ['email' => true, 'webhook_url' => 'https://hooks.example.com/reports'], 'sftp' => ['host' => 'sftp.example.com', 'username' => 'reports', 'path' => '/in']]))
        ->assertOk()->assertJsonPath('data.webhook_secret', null)->assertJsonPath('data.schedule.next_run_at', '2027-03-16T07:30:00+00:00');
    $stored->refresh();
    expect($stored->secret_config_encrypted['webhook_secret'])->toBe($secret)->and($stored->secret_config_encrypted['sftp']['password'])->toBe('a-secret-password');
});

test('schedules are refused when the cron, the channels or the recipients are not acceptable', function (): void {
    $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['cron_expression' => '* * * * *']))->assertUnprocessable()->assertJsonValidationErrors('cron_expression');
    $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['timezone' => 'Mars/Olympus']))->assertUnprocessable()->assertJsonValidationErrors('timezone');
    $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['locale' => 'es-ES']))->assertUnprocessable()->assertJsonValidationErrors('locale');
    $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['format' => 'docx']))->assertUnprocessable()->assertJsonValidationErrors('format');
    $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['recipient_user_ids' => [], 'delivery_channels' => []]))->assertUnprocessable()->assertJsonValidationErrors('delivery_channels');
    $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['recipient_user_ids' => [biUser(biTenant('Elsewhere'))->id]]))->assertUnprocessable()->assertJsonValidationErrors('recipient_user_ids.0');
    $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['external_emails' => ['someone@external.example']]))->assertUnprocessable()->assertJsonValidationErrors('external_emails.0');
    $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['delivery_channels' => ['webhook_url' => 'http://hooks.example.com/plain']]))->assertUnprocessable()->assertJsonValidationErrors('delivery_channels.webhook_url');
    $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['delivery_channels' => ['dms_folder_id' => (string) Str::uuid()]]))->assertUnprocessable()->assertJsonValidationErrors('delivery_channels.dms_folder_id');
    $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['delivery_channels' => ['telegram_chat_ids' => ['not a chat; DROP']]]))->assertUnprocessable()->assertJsonValidationErrors('delivery_channels.telegram_chat_ids.0');
    $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['sftp' => ['host' => 'evil.example.com/../x', 'username' => 'u'], 'recipient_user_ids' => []]))->assertUnprocessable()->assertJsonValidationErrors('sftp.host');
    $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['designed_report_id' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('designed_report_id');
    expect(ExportSchedule::query()->count())->toBe(0);
});

test('the dispatcher queues each due cron schedule once and moves it forward before the job runs', function (): void {
    Queue::fake();
    $due = ExportSchedule::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'designed_report_id' => $this->report->id, 'frequency' => 'cron', 'cron_expression' => '0 * * * *', 'timezone' => 'UTC', 'next_run_at' => '2027-03-15 09:00:00', 'next_run_on' => null, 'delivery_channels' => ['email' => true], 'recipients' => []]);
    $later = ExportSchedule::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'designed_report_id' => $this->report->id, 'frequency' => 'cron', 'cron_expression' => '0 * * * *', 'timezone' => 'UTC', 'next_run_at' => '2027-03-15 11:00:00', 'next_run_on' => null, 'delivery_channels' => ['email' => true], 'recipients' => []]);
    $stopped = ExportSchedule::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'designed_report_id' => $this->report->id, 'frequency' => 'cron', 'cron_expression' => '0 * * * *', 'timezone' => 'UTC', 'next_run_at' => '2027-03-15 08:00:00', 'next_run_on' => null, 'is_active' => false, 'delivery_channels' => ['email' => true], 'recipients' => []]);

    expect(Artisan::call('analytics:dispatch-scheduled-reports', ['--tenant' => [$this->tenant->id]]))->toBe(0);

    Queue::assertPushed(ExecuteScheduledReportJob::class, 1);
    Queue::assertPushed(ExecuteScheduledReportJob::class, fn (ExecuteScheduledReportJob $job): bool => $job->scheduleId === $due->id && $job->tenantId === $this->tenant->id);
    expect($due->refresh()->next_run_at->toIso8601String())->toBe('2027-03-15T11:00:00+00:00')
        ->and($later->refresh()->next_run_at->toIso8601String())->toBe('2027-03-15T11:00:00+00:00')
        ->and($stopped->refresh()->next_run_at->toIso8601String())->toBe('2027-03-15T08:00:00+00:00');

    // Running the dispatcher again in the same minute queues nothing: the schedule was claimed.
    Artisan::call('analytics:dispatch-scheduled-reports', ['--tenant' => [$this->tenant->id]]);
    Queue::assertPushed(ExecuteScheduledReportJob::class, 1);

    // An hour later the next occurrence is due.
    Carbon::setTestNow('2027-03-15 11:00:30');
    Artisan::call('analytics:dispatch-scheduled-reports', ['--tenant' => [$this->tenant->id]]);
    Queue::assertPushed(ExecuteScheduledReportJob::class, 3);
});

test('the scheduler runs the dispatcher every minute and does not touch dated schedules', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'analytics:dispatch-scheduled-reports'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');

    // A dated schedule is for the daily command only: the cron dispatcher and its job leave its calendar alone.
    Queue::fake();
    $dated = ExportSchedule::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'designed_report_id' => $this->report->id, 'next_run_on' => '2027-03-10']);
    Artisan::call('analytics:dispatch-scheduled-reports', ['--tenant' => [$this->tenant->id]]);
    Queue::assertNothingPushed();
    expect($dated->refresh()->next_run_on->toDateString())->toBe('2027-03-10');
});

test('a run generates the artifact in the locale and format of the schedule and delivers it to every channel', function (): void {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]]),
        'hooks.example.com/*' => Http::response('', 204),
    ]);
    CommunicationChannel::create(['tenant_id' => $this->tenant->id, 'channel' => 'telegram', 'driver' => 'telegram', 'is_enabled' => true, 'credentials' => ['bot_token' => '123456:ABC-test']]);
    $folder = DmsFolder::create(['tenant_id' => $this->tenant->id, 'name' => 'Reports']);
    config(['analytics.reporting.allow_external_recipients' => true]);

    $created = $this->postJson('/api/v1/analytics/export-schedules', cronPayload([
        'format' => 'csv',
        'external_emails' => ['auditor@external.example'],
        'delivery_channels' => ['email' => true, 'telegram_chat_ids' => ['-100123456'], 'dms_folder_id' => $folder->id, 'webhook_url' => 'https://hooks.example.com/reports'],
    ]))->assertCreated();
    $scheduleId = $created->json('data.schedule.id');
    $secret = $created->json('data.webhook_secret');

    (new ExecuteScheduledReportJob($this->tenant->id, $scheduleId))->handle(app(TenantContext::class), app(ScheduledExportService::class));

    $schedule = ExportSchedule::query()->findOrFail($scheduleId);
    expect($schedule->last_status)->toBe('ok')->and($schedule->last_error)->toBeNull();
    $run = ExportRun::query()->where('schedule_id', $scheduleId)->sole();
    expect($run->status)->toBe('completed')->and($run->locale)->toBe('it-CH')->and($run->format)->toBe('csv')->and($run->row_count)->toBe(1);
    $file = Storage::disk((string) $run->disk)->get((string) $run->path);
    expect($file)->toStartWith("\"Cliente\";\"Importo netto\"\r\n")->and($file)->toContain("\"Rossi\";\"1'234.50\"");

    // E-mail: the colleague and the external address, with the file attached and a signed link, in Italian.
    Mail::assertSent(ScheduledReportMail::class, 2);
    Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail): bool => $mail->hasTo($this->colleague->email) && $mail->attachmentName === $run->file_name && $mail->attachmentMime === 'text/csv; charset=UTF-8' && str_contains($mail->link, 'signature='));
    Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail): bool => $mail->hasTo('auditor@external.example') && $mail->locale === 'it');

    // Telegram: the document goes to the chat through the bot of the company.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'bot123456:ABC-test/sendDocument') && $request->isMultipart() && collect($request->data())->contains(fn ($part): bool => ($part['name'] ?? null) === 'chat_id' && ($part['contents'] ?? null) === '-100123456'));

    // DMS: the file is archived in the folder, authored by the owner, and linked on the run.
    $document = DmsDocument::query()->where('document_type', 'analytics_export')->sole();
    expect($document->folder_id)->toBe($folder->id)->and($run->refresh()->document_id)->toBe($document->id)->and((string) $document->created_by)->toBe((string) $this->user->id);

    // Webhook: a signed payload with the link to the file.
    Http::assertSent(function (Request $request) use ($secret, $run): bool {
        if ($request->url() !== 'https://hooks.example.com/reports') {
            return false;
        }

        $body = $request->body();

        return WebhookSigner::verify($request->header(WebhookSigner::SignatureHeader)[0], $body, $secret)
            && json_decode($body, true)['run_id'] === $run->id
            && json_decode($body, true)['event'] === 'analytics.report.generated'
            && str_contains((string) json_decode($body, true)['download_url'], 'signature=');
    });
});

test('one failing channel does not stop the others and the failure is recorded on the schedule and the log', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('boom', 500)]);
    $created = $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['delivery_channels' => ['email' => true, 'telegram_chat_ids' => ['-100123456'], 'webhook_url' => 'https://hooks.example.com/reports']]))->assertCreated();
    $scheduleId = $created->json('data.schedule.id');

    (new ExecuteScheduledReportJob($this->tenant->id, $scheduleId))->handle(app(TenantContext::class), app(ScheduledExportService::class));

    $schedule = ExportSchedule::query()->findOrFail($scheduleId);
    expect($schedule->last_status)->toBe('failed')
        ->and($schedule->last_error)->toContain('telegram: The Telegram bot is not configured.')
        ->and($schedule->last_error)->toContain('webhook: The endpoint answered HTTP 500.');
    Mail::assertSent(ScheduledReportMail::class, 1);
    // The artifact was produced: the log says so, the failure is about delivery.
    expect(ExportRun::query()->where('schedule_id', $scheduleId)->sole()->status)->toBe('completed');
});

test('webhooks and sftp hosts that point inside the network are refused', function (): void {
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(new StaticHostResolver(['internal.example.com' => ['10.0.0.5']])));
    Http::fake();
    $created = $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['recipient_user_ids' => [], 'delivery_channels' => ['webhook_url' => 'https://internal.example.com/hook']]))->assertCreated();

    (new ExecuteScheduledReportJob($this->tenant->id, $created->json('data.schedule.id')))->handle(app(TenantContext::class), app(ScheduledExportService::class));

    expect(ExportSchedule::query()->findOrFail($created->json('data.schedule.id'))->last_status)->toBe('failed');
    Http::assertNothingSent();
});

test('run now queues the schedule outside its calendar, for those who may manage it', function (): void {
    Queue::fake();
    $scheduleId = $this->postJson('/api/v1/analytics/export-schedules', cronPayload())->assertCreated()->json('data.schedule.id');
    $next = ExportSchedule::query()->findOrFail($scheduleId)->next_run_at->toIso8601String();

    $this->postJson("/api/v1/analytics/export-schedules/{$scheduleId}/run")->assertAccepted()->assertJsonPath('data.id', $scheduleId);
    Queue::assertPushed(ExecuteScheduledReportJob::class, fn (ExecuteScheduledReportJob $job): bool => $job->scheduleId === $scheduleId);
    expect(ExportSchedule::query()->findOrFail($scheduleId)->next_run_at->toIso8601String())->toBe($next);

    // A colleague who does not own it cannot trigger it; an administrator can.
    biActAs($this->tenant, 'accountant');
    $this->postJson("/api/v1/analytics/export-schedules/{$scheduleId}/run")->assertForbidden();
    biActAs($this->tenant, 'admin');
    $this->postJson("/api/v1/analytics/export-schedules/{$scheduleId}/run")->assertAccepted();

    // And another company never sees it.
    biActAs(biTenant('Other'));
    $this->postJson("/api/v1/analytics/export-schedules/{$scheduleId}/run")->assertNotFound();
});

test('a schedule runs with the permissions its owner has today, also for a pivot report', function (): void {
    $pivot = DesignedReport::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'name' => 'Matrix', 'dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount']), 'pivot' => ['rows' => ['customer'], 'columns' => [], 'subtotals' => true, 'grand_totals' => true]]);
    $scheduleId = $this->postJson('/api/v1/analytics/export-schedules', cronPayload(['designed_report_id' => $pivot->id, 'format' => 'json', 'locale' => 'en']))->assertCreated()->json('data.schedule.id');

    (new ExecuteScheduledReportJob($this->tenant->id, $scheduleId))->handle(app(TenantContext::class), app(ScheduledExportService::class));

    $run = ExportRun::query()->where('schedule_id', $scheduleId)->sole();
    $json = json_decode(Storage::disk((string) $run->disk)->get((string) $run->path), true, flags: JSON_THROW_ON_ERROR);
    expect($json['rows'])->toHaveCount(2)->and($json['rows'][1]['__type'])->toBe('grand_total')->and($json['rows'][1]['t_net_amount'])->toBe('1234.50');

    // The owner loses the company: the schedule fails and delivers nothing more.
    $this->user->tenants()->detach($this->tenant->id);
    (new ExecuteScheduledReportJob($this->tenant->id, $scheduleId))->handle(app(TenantContext::class), app(ScheduledExportService::class));
    expect(ExportSchedule::withoutGlobalScopes()->findOrFail($scheduleId)->last_status)->toBe('failed')->and(ExportRun::withoutGlobalScopes()->where('schedule_id', $scheduleId)->count())->toBe(1);
});
