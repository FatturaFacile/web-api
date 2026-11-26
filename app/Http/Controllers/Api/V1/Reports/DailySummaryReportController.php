<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\PreviewDailySummaryRequest;
use App\Http\Requests\Reports\SendNowDailySummaryRequest;
use App\Http\Resources\Reports\ScheduledReportAuditLogResource;
use App\Http\Resources\Reports\ScheduledReportDeliveryResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\Reports\ProcessScheduledReportChunkJob;
use App\Mail\ScheduledErpDailyReportMail;
use App\Models\EmailBlacklist;
use App\Models\NotificationPreference;
use App\Models\ScheduledReportAuditLog;
use App\Models\ScheduledReportDelivery;
use App\Models\Tenant;
use App\Models\TenantReportSetting;
use App\Models\User;
use App\Services\Reports\DailySummary\ErpDailySummaryAggregator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class DailySummaryReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ErpDailySummaryAggregator $aggregator,
    ) {}

    #[OA\Get(
        path: '/api/v1/reports/daily-summary/preview',
        operationId: 'previewDailySummaryReport',
        description: 'Render live HTML, plain-text, and structured JSON preview of the current user ERP daily summary report.',
        summary: 'Preview ERP daily summary report',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Scheduled ERP Reports'],
        responses: [
            new OA\Response(response: 200, description: 'Report preview generated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function preview(PreviewDailySummaryRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = $this->tenantContext->getTenant();
        if (! $tenant instanceof Tenant) {
            return $this->problemDetailsResponse('Bad Request', 'Tenant context is required.', Response::HTTP_BAD_REQUEST);
        }

        $settings = TenantReportSetting::query()->where('tenant_id', $tenant->id)->first();
        $preference = NotificationPreference::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->first();

        $validated = $request->validated();
        $defaultLocale = $tenant->country === 'IT' ? 'it-IT' : 'it-CH';
        $locale = (string) ($validated['language'] ?? ($preference !== null ? $preference->language : $defaultLocale));

        $requestedModules = [];
        foreach (NotificationPreference::MODULES as $module) {
            $key = "include_{$module}";
            $enabled = array_key_exists($key, $validated)
                ? filter_var($validated[$key], FILTER_VALIDATE_BOOLEAN)
                : ($preference !== null ? (bool) $preference->getAttribute($key) : true);

            if ($enabled) {
                $requestedModules[] = $module;
            }
        }

        $summary = $this->aggregator->aggregate(
            $tenant,
            $user,
            $locale,
            $settings,
            $requestedModules,
            useCache: false,
        );

        $mailable = new ScheduledErpDailyReportMail(
            tenant: $tenant,
            user: $user,
            summary: $summary,
            reportLocale: $locale,
            settings: $settings,
            isTest: false,
        );

        $viewData = $mailable->viewDataPayload();
        $html = view('emails.reports.daily-summary', $viewData)->render();
        $text = view('emails.reports.daily-summary-text', $viewData)->render();

        return $this->successResponse(
            data: [
                'html' => $html,
                'text' => $text,
                'summary' => $summary,
            ],
            message: 'Daily summary report preview generated'
        );
    }

    #[OA\Post(
        path: '/api/v1/reports/daily-summary/send-test',
        operationId: 'sendTestDailySummaryReport',
        description: 'Queue a test ERP daily summary report email immediately for the authenticated user.',
        summary: 'Send test ERP daily summary report',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Scheduled ERP Reports'],
        responses: [
            new OA\Response(response: 202, description: 'Test report queued'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
    public function sendTest(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = $this->tenantContext->getTenant();
        if (! $tenant instanceof Tenant) {
            return $this->problemDetailsResponse('Bad Request', 'Tenant context is required.', Response::HTTP_BAD_REQUEST);
        }

        $rateKey = "report-send-test:{$tenant->id}:{$user->id}";
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return $this->problemDetailsResponse('Too Many Requests', 'Please wait before sending another test report.', Response::HTTP_TOO_MANY_REQUESTS);
        }
        RateLimiter::hit($rateKey, 300);

        $preference = $this->ensurePreference($tenant, $user);

        ProcessScheduledReportChunkJob::dispatch(
            (string) $tenant->id,
            [(string) $preference->id],
            'test-'.now()->format('Y-m-d-H-i-s'),
            isTest: true,
            forceOnDemand: true,
        );

        return $this->successResponse(
            data: ['queued' => true, 'recipient_email' => $user->email, 'is_test' => true],
            message: 'Test daily summary report queued',
            statusCode: Response::HTTP_ACCEPTED
        );
    }

    #[OA\Post(
        path: '/api/v1/reports/daily-summary/send-now',
        operationId: 'sendNowDailySummaryReport',
        description: 'Queue an on-demand ERP daily summary report dispatch for the current user or a specified tenant member.',
        summary: 'Send ERP daily summary report now',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Scheduled ERP Reports'],
        responses: [
            new OA\Response(response: 202, description: 'Report queued'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Recipient blacklisted'),
        ]
    )]
    public function sendNow(SendNowDailySummaryRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $tenant = $this->tenantContext->getTenant();
        if (! $tenant instanceof Tenant) {
            return $this->problemDetailsResponse('Bad Request', 'Tenant context is required.', Response::HTTP_BAD_REQUEST);
        }

        $targetUserId = $request->validated('user_id');
        $targetUser = $actor;

        if (is_string($targetUserId) && $targetUserId !== (string) $actor->id) {
            if (! in_array($actor->getRoleInTenant((string) $tenant->id), ['owner', 'admin'], true)) {
                return $this->problemDetailsResponse('Forbidden', 'Only tenant administrators can trigger reports for other users.', Response::HTTP_FORBIDDEN);
            }

            $member = $tenant->users()->where('users.id', $targetUserId)->first();
            if (! $member instanceof User) {
                return $this->problemDetailsResponse('Not Found', 'Target user is not a member of this tenant.', Response::HTTP_NOT_FOUND);
            }
            $targetUser = $member;
        }

        if (EmailBlacklist::isBlacklisted((string) $targetUser->email, (string) $tenant->id)) {
            return $this->problemDetailsResponse('Unprocessable Entity', 'Recipient email is on the suppression blacklist.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $preference = $this->ensurePreference($tenant, $targetUser);

        ProcessScheduledReportChunkJob::dispatch(
            (string) $tenant->id,
            [(string) $preference->id],
            'manual-'.now()->format('Y-m-d-H-i-s'),
            isTest: false,
            forceOnDemand: true,
        );

        return $this->successResponse(
            data: ['queued' => true, 'recipient_email' => $targetUser->email, 'is_test' => false],
            message: 'Daily summary report queued',
            statusCode: Response::HTTP_ACCEPTED
        );
    }

    #[OA\Get(
        path: '/api/v1/reports/daily-summary/audit-logs',
        operationId: 'listDailySummaryAuditLogs',
        description: 'Retrieve paginated delivery history and audit logs for scheduled ERP daily summary reports.',
        summary: 'List scheduled ERP report delivery and audit logs',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Scheduled ERP Reports'],
        responses: [
            new OA\Response(response: 200, description: 'Audit logs retrieved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function auditLogs(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = $this->tenantContext->getTenant();
        $tenantId = $tenant !== null ? (string) $tenant->id : '';
        $isAdmin = in_array($user->getRoleInTenant($tenantId), ['owner', 'admin'], true);

        $deliveriesQuery = ScheduledReportDelivery::query()->where('tenant_id', $tenantId)->latest();
        $auditQuery = ScheduledReportAuditLog::query()->where('tenant_id', $tenantId)->latest('occurred_at');

        if (! $isAdmin) {
            $deliveriesQuery->where('user_id', $user->id);
            $auditQuery->where('user_id', $user->id);
        }

        $deliveries = $deliveriesQuery->paginate(15, ['*'], 'deliveries_page');
        $audits = $auditQuery->paginate(20, ['*'], 'audit_page');

        return $this->successResponse(
            data: [
                'deliveries' => ScheduledReportDeliveryResource::collection($deliveries->items())->resolve($request),
                'audit_logs' => ScheduledReportAuditLogResource::collection($audits->items())->resolve($request),
            ],
            message: 'Scheduled report delivery and audit logs retrieved',
            meta: [
                'deliveries_total' => $deliveries->total(),
                'audit_total' => $audits->total(),
            ]
        );
    }

    private function ensurePreference(Tenant $tenant, User $user): NotificationPreference
    {
        $settings = TenantReportSetting::query()->where('tenant_id', $tenant->id)->first();

        return NotificationPreference::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            [
                'enabled' => true,
                'frequency' => $settings !== null ? $settings->default_frequency : NotificationPreference::FREQUENCY_DAILY,
                'send_time' => $settings !== null ? $settings->default_send_time : '07:00',
                'language' => $tenant->country === 'IT' ? 'it-IT' : 'it-CH',
            ]
        );
    }
}
