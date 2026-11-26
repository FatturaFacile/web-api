<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tenant;
use App\Models\TenantReportSetting;
use App\Models\User;
use App\Services\Reports\DailySummary\ReportLocaleHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Queued responsive HTML + plain-text email for the scheduled ERP daily summary report.
 */
final class ScheduledErpDailyReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $user,
        public readonly array $summary,
        public readonly string $reportLocale = 'it-CH',
        public readonly ?TenantReportSetting $settings = null,
        public readonly string $unsubscribeUrl = '',
        public readonly string $openPixelUrl = '',
        public readonly string $clickBaseUrl = '',
        public readonly bool $isTest = false,
    ) {}

    public function envelope(): Envelope
    {
        $helper = app(ReportLocaleHelper::class);
        $subjectBase = $helper->trans('SCHEDULED_REPORT.EMAIL.SUBJECT', $this->reportLocale, [
            'company' => $this->tenant->name,
            'date' => (string) ($this->summary['report_date'] ?? now()->toDateString()),
        ], $this->tenant->id);

        $subject = $this->isTest ? '[TEST] '.$subjectBase : $subjectBase;

        $fromAddress = $this->settings?->sender_email ?: (string) config('mail.from.address', 'hello@example.com');
        $fromName = $this->settings?->sender_name ?: ($this->tenant->name ?: (string) config('mail.from.name', 'FatturaFacile'));

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reports.daily-summary',
            text: 'emails.reports.daily-summary-text',
            with: $this->viewDataPayload(),
        );
    }

    public function headers(): Headers
    {
        if ($this->unsubscribeUrl === '') {
            return new Headers;
        }

        return new Headers(
            text: [
                'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function viewDataPayload(): array
    {
        $helper = app(ReportLocaleHelper::class);
        $frontendUrl = rtrim((string) config('app.frontend_url', 'https://localhost:4200'), '/');
        $primaryColor = $this->settings?->primary_color ?: '#1890ff';
        $secondaryColor = $this->settings?->secondary_color ?: '#001529';

        $wrapClickUrl = function (string $path) use ($frontendUrl): string {
            $target = $frontendUrl.$path;
            if ($this->clickBaseUrl === '') {
                return $target;
            }

            $separator = str_contains($this->clickBaseUrl, '?') ? '&' : '?';

            return $this->clickBaseUrl.$separator.'target='.urlencode($path);
        };

        return [
            'tenant' => $this->tenant,
            'user' => $this->user,
            'summary' => $this->summary,
            'reportLocale' => $this->reportLocale,
            'settings' => $this->settings,
            'primaryColor' => $primaryColor,
            'secondaryColor' => $secondaryColor,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'openPixelUrl' => $this->openPixelUrl,
            'dashboardUrl' => $wrapClickUrl('/dashboard'),
            'invoicesUrl' => $wrapClickUrl('/sales/invoices'),
            'preferencesUrl' => $wrapClickUrl('/settings/notifications'),
            'isTest' => $this->isTest,
            't' => fn (string $key, array $params = []): string => $helper->trans($key, $this->reportLocale, $params, $this->tenant->id),
            'money' => fn (float $amount): string => $helper->formatCurrency($amount, (string) ($this->summary['currency'] ?? 'EUR'), $this->reportLocale),
        ];
    }
}
