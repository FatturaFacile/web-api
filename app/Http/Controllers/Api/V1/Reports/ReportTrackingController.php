<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EmailBlacklist;
use App\Models\NotificationPreference;
use App\Models\ScheduledReportAuditLog;
use App\Models\ScheduledReportDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public signed tracking and one-click unsubscribe endpoints for scheduled ERP daily reports.
 */
final class ReportTrackingController extends Controller
{
    use ApiResponse;

    /**
     * @var list<string>
     */
    private const ALLOWED_CLICK_PREFIXES = [
        '/dashboard',
        '/sales/invoices',
        '/settings/notifications',
        '/crm',
        '/inventory',
        '/field-service',
        '/appointments',
    ];

    /**
     * 1x1 transparent GIF binary.
     */
    private const TRANSPARENT_GIF = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    public function unsubscribe(Request $request, string $preference): JsonResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->problemDetailsResponse('Forbidden', 'Invalid or expired unsubscribe signature.', Response::HTTP_FORBIDDEN);
        }

        $pref = NotificationPreference::query()
            ->withoutGlobalScopes()
            ->whereKey($preference)
            ->where('channel', NotificationPreference::CHANNEL_ERP_REPORT)
            ->with('user')
            ->first();

        if ($pref === null) {
            return $this->problemDetailsResponse('Not Found', 'Notification preference not found.', Response::HTTP_NOT_FOUND);
        }

        $pref->update([
            'enabled' => false,
            'unsubscribed_at' => now(),
        ]);

        $email = $pref->user?->email;
        if (is_string($email) && $email !== '') {
            EmailBlacklist::query()->updateOrCreate(
                [
                    'tenant_id' => $pref->tenant_id,
                    'email' => strtolower(trim($email)),
                ],
                [
                    'reason' => EmailBlacklist::REASON_UNSUBSCRIBED,
                    'notes' => 'One-click unsubscribe from scheduled ERP daily report',
                ]
            );
        }

        ScheduledReportAuditLog::query()->create([
            'tenant_id' => $pref->tenant_id,
            'user_id' => $pref->user_id,
            'event_type' => ScheduledReportAuditLog::EVENT_UNSUBSCRIBED,
            'recipient_email' => $email,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'occurred_at' => now(),
        ]);

        return $this->successResponse(
            data: ['unsubscribed' => true, 'preference_id' => $pref->id],
            message: 'You have been unsubscribed from scheduled ERP daily reports.'
        );
    }

    public function trackOpen(Request $request, string $delivery): HttpResponse
    {
        if (! $request->hasValidSignature()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $record = ScheduledReportDelivery::query()->withoutGlobalScopes()->whereKey($delivery)->first();

        if ($record !== null) {
            $record->increment('open_count');
            $record->update([
                'opened_at' => $record->opened_at ?? now(),
                'status' => $record->clicked_at === null ? ScheduledReportDelivery::STATUS_OPENED : $record->status,
            ]);

            ScheduledReportAuditLog::query()->create([
                'tenant_id' => $record->tenant_id,
                'user_id' => $record->user_id,
                'delivery_id' => $record->id,
                'event_type' => ScheduledReportAuditLog::EVENT_EMAIL_OPENED,
                'recipient_email' => $record->recipient_email,
                'period_key' => $record->period_key,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'occurred_at' => now(),
            ]);
        }

        return response(self::TRANSPARENT_GIF, Response::HTTP_OK, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function trackClick(Request $request, string $delivery): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $targetPath = $this->sanitizeTargetPath((string) $request->query('target', '/dashboard'));
        $frontendUrl = rtrim((string) config('app.frontend_url', 'https://localhost:4200'), '/');
        $redirectUrl = $frontendUrl.$targetPath;

        $record = ScheduledReportDelivery::query()->withoutGlobalScopes()->whereKey($delivery)->first();

        if ($record !== null) {
            $record->increment('click_count');
            $record->update([
                'clicked_at' => $record->clicked_at ?? now(),
                'status' => ScheduledReportDelivery::STATUS_CLICKED,
            ]);

            ScheduledReportAuditLog::query()->create([
                'tenant_id' => $record->tenant_id,
                'user_id' => $record->user_id,
                'delivery_id' => $record->id,
                'event_type' => ScheduledReportAuditLog::EVENT_LINK_CLICKED,
                'recipient_email' => $record->recipient_email,
                'period_key' => $record->period_key,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'metadata' => ['target' => $targetPath],
                'occurred_at' => now(),
            ]);
        }

        return redirect()->away($redirectUrl);
    }

    private function sanitizeTargetPath(string $target): string
    {
        $trimmed = trim($target);
        if ($trimmed === '' || str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://') || str_starts_with($trimmed, '//')) {
            return '/dashboard';
        }

        foreach (self::ALLOWED_CLICK_PREFIXES as $prefix) {
            if ($trimmed === $prefix || str_starts_with($trimmed, $prefix.'/')) {
                return $trimmed;
            }
        }

        return '/dashboard';
    }
}
