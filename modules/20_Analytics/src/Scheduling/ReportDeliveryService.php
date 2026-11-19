<?php

declare(strict_types=1);

namespace Modules\Analytics\Scheduling;

use App\Models\User;
use App\Services\Security\OutboundUrlGuard;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Analytics\Mail\ScheduledReportMail;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportRun;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Communication\Models\CommunicationChannel;
use Modules\Documents\Models\DmsFolder;
use Modules\Documents\Services\DocumentLibrary;
use Modules\Integrations\Webhooks\WebhookSigner;
use Modules\Integrations\Webhooks\WebhookUrlGuard;
use RuntimeException;
use Throwable;

/**
 * Hands the file of a scheduled report to every delivery channel of its schedule: e-mail (internal users and external
 * addresses, with the file attached and a signed link), Telegram (document upload through the bot of the company), the
 * document library (a folder of the Documents module), a signed webhook and SFTP. Channels are independent: one that
 * fails does not stop the others, and the failures are reported together so the schedule records them.
 *
 * Outbound hosts (webhook URL, SFTP host) go through the same SSRF guard as the rest of the platform, and the webhook
 * is signed with the secret of the schedule (header `X-FF-Webhook-Signature`, the scheme of the integrations module).
 */
final readonly class ReportDeliveryService
{
    private const int MaxAttachmentBytes = 10_485_760;

    private const int TelegramMaxBytes = 50_000_000;

    public function __construct(
        private TenantContext $tenantContext,
        private ModuleAccessGate $gate,
        private DocumentLibrary $documents,
        private WebhookUrlGuard $webhookGuard,
        private OutboundUrlGuard $outbound,
        private WebhookSigner $signer,
    ) {}

    /**
     * Sends the file to the channels of the schedule.
     *
     * @return list<string> one message per channel that failed, empty when every channel succeeded
     */
    public function deliver(ExportSchedule $schedule, DesignedReport $report, ExportRun $run, string $path, string $fileName, string $mime, User $owner, string $language): array
    {
        $channels = $schedule->delivery_channels;
        $failures = [];
        $link = $run->expires_at === null ? null : URL::temporarySignedRoute('analytics.exports.download', $run->expires_at, ['run' => $run->id]);
        $attempt = function (string $channel, callable $send) use (&$failures): void {
            try {
                $send();
            } catch (Throwable $exception) {
                report($exception);
                $failures[] = $channel.': '.mb_substr($exception->getMessage(), 0, 200);
            }
        };

        $wantsEmail = ($channels['email'] ?? false) || $schedule->recipient_user_ids !== [] || $schedule->external_emails !== [];

        if ($wantsEmail) {
            $attempt('email', fn () => $this->email($schedule, $report, $run, $path, $fileName, $mime, $link, $owner, $language));
        }

        foreach ((array) ($channels['telegram_chat_ids'] ?? []) as $chatId) {
            $attempt('telegram', fn () => $this->telegram((string) $chatId, $report, $path, $fileName, $language));
        }

        if (! empty($channels['dms_folder_id']) || ! empty($channels['dms'])) {
            $attempt('dms', fn () => $this->dms(empty($channels['dms_folder_id']) ? null : (string) $channels['dms_folder_id'], $report, $run, $path, $fileName, $mime, $owner));
        }

        if (! empty($channels['webhook_url'])) {
            $attempt('webhook', fn () => $this->webhook((string) $channels['webhook_url'], $schedule, $report, $run, $link));
        }

        $sftp = (array) ($schedule->secret_config_encrypted['sftp'] ?? []);

        if ($sftp !== []) {
            $attempt('sftp', fn () => $this->sftp($sftp, $path, $fileName));
        }

        return $failures;
    }

    private function email(ExportSchedule $schedule, DesignedReport $report, ExportRun $run, string $path, string $fileName, string $mime, ?string $link, User $owner, string $language): void
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $members = $schedule->recipient_user_ids === [] ? collect() : User::query()
            ->whereIn('id', $schedule->recipient_user_ids)
            ->whereHas('tenants', static fn ($query) => $query->where('tenants.id', $tenantId))
            ->get();
        $addresses = collect([...$schedule->recipients, ...$schedule->external_emails, ...$members->pluck('email')->all()])
            ->map(static fn (mixed $email): string => strtolower((string) $email))
            ->filter()
            ->unique()
            ->values();
        $attach = is_file($path) && filesize($path) <= self::MaxAttachmentBytes;
        $failures = [];

        foreach ($addresses as $address) {
            try {
                Mail::to($address)->locale($language)->send(new ScheduledReportMail($report->name, (string) $link, (string) $run->expires_at?->toDateString(), $run->row_count, $run->truncated, $attach ? $path : null, $fileName, $mime));
            } catch (Throwable $exception) {
                report($exception);
                $failures[] = $address;
            }
        }

        if ($failures !== []) {
            throw new RuntimeException('The e-mail could not be sent to: '.implode(', ', $failures));
        }
    }

    private function telegram(string $chatId, DesignedReport $report, string $path, string $fileName, string $language): void
    {
        $channel = CommunicationChannel::query()->where('channel', 'telegram')->where('is_enabled', true)->first();
        $token = (string) (($channel->credentials ?? [])['bot_token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('The Telegram bot is not configured.');
        }

        if (preg_match('/^(-?\d{1,20}|@[A-Za-z0-9_]{4,64})$/', $chatId) !== 1) {
            throw new RuntimeException('The Telegram chat is not valid.');
        }

        if (filesize($path) > self::TelegramMaxBytes) {
            throw new RuntimeException('The file is too large for Telegram.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The file could not be read.');
        }

        try {
            $response = Http::timeout(60)->attach('document', $handle, $fileName)->post('https://api.telegram.org/bot'.strtr(rawurlencode($token), ['%3A' => ':']).'/sendDocument', ['chat_id' => $chatId, 'caption' => mb_substr($report->name, 0, 200)]);
        } finally {
            fclose($handle);
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException((string) ($response->json('description') ?? 'Telegram refused the document.'));
        }
    }

    private function dms(?string $folderId, DesignedReport $report, ExportRun $run, string $path, string $fileName, string $mime, User $owner): void
    {
        $tenantId = (string) $this->tenantContext->getTenantId();

        if (! $this->gate->allows($owner, $tenantId, 'documents', 'write')) {
            throw new RuntimeException('The owner of this schedule cannot write documents.');
        }

        $folder = $folderId === null ? null : (DmsFolder::query()->find($folderId) ?? throw new RuntimeException('The document folder does not exist.'));
        $document = $this->documents->create(
            ['folder_id' => $folder?->id, 'title' => $report->name.' ('.now()->toDateString().')', 'document_type' => 'analytics_export', 'description' => 'Scheduled export of the report "'.$report->name.'"', 'metadata' => ['analytics_export_run_id' => $run->id, 'dataset' => $run->dataset]],
            new UploadedFile($path, $fileName, $mime, null, true),
            null,
            true,
            [],
            $owner,
        );
        $run->update(['document_id' => $document->id]);
    }

    private function webhook(string $url, ExportSchedule $schedule, DesignedReport $report, ExportRun $run, ?string $link): void
    {
        $secret = (string) ($schedule->secret_config_encrypted['webhook_secret'] ?? '');

        if ($secret === '') {
            throw new RuntimeException('The webhook has no signing secret.');
        }

        $this->webhookGuard->assertSafe($url);
        $body = json_encode([
            'event' => 'analytics.report.generated',
            'report' => ['id' => $report->id, 'name' => $report->name, 'dataset' => $report->dataset],
            'schedule_id' => $schedule->id,
            'run_id' => $run->id,
            'format' => $run->format,
            'locale' => $run->locale,
            'row_count' => $run->row_count,
            'truncated' => $run->truncated,
            'sha256' => $run->sha256,
            'download_url' => $link,
            'download_expires_at' => $run->expires_at?->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = time();

        $request = Http::withHeaders([
            'User-Agent' => 'FatturaFacile-Reports',
            'X-FF-Webhook-Event' => 'analytics.report.generated',
            WebhookSigner::SignatureHeader => $this->signer->header($timestamp, $body, [$secret]),
        ])->withBody($body, 'application/json')->connectTimeout(5)->timeout(15)->withOptions(array_merge(['proxy' => ''], app()->environment('testing') ? (array) config('webhooks.testing_http_options', []) : []));
        $response = $this->outbound->protect($request)->post($url);

        if (! $response->successful()) {
            throw new RuntimeException('The endpoint answered HTTP '.$response->status().'.');
        }
    }

    /**
     * @param  array<string, mixed>  $config  host, port, username, password or private_key, path
     */
    private function sftp(array $config, string $path, string $fileName): void
    {
        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 22);

        if (! app()->environment('testing')) {
            $this->outbound->inspectUrl('https://'.$host.':'.$port, ['https']);
        }

        $disk = Storage::build([
            'driver' => 'sftp',
            'host' => $host,
            'port' => $port,
            'username' => (string) ($config['username'] ?? ''),
            'password' => $config['password'] ?? null,
            'privateKey' => $config['private_key'] ?? null,
            'root' => '/'.trim((string) ($config['path'] ?? ''), '/'),
            'timeout' => 15,
            'throw' => true,
        ]);
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException('The file could not be read.');
        }

        try {
            $disk->writeStream($fileName, $stream);
        } finally {
            fclose($stream);
        }
    }
}
