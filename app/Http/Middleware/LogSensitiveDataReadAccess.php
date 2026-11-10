<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Privacy\GlobalPrivacyComplianceService;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs read/export access to sensitive HR, Payroll, and Privacy personal data (Swiss DPOOP Art. 4).
 */
final class LogSensitiveDataReadAccess
{
    public function __construct(
        private readonly GlobalPrivacyComplianceService $privacyService,
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next, string $dataCategory = 'sensitive_personal_data'): Response
    {
        $response = $next($request);

        if ($response->isSuccessful() && in_array($request->method(), ['GET', 'POST'], true)) {
            $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
            if ($tenantId) {
                $subjectId = (string) ($request->route('id') ?? $request->route('employee') ?? $request->route('subjectId') ?? 'collection');
                $subjectType = str_contains($request->path(), 'payroll') || str_contains($request->path(), 'hr') ? 'employee' : 'subject';

                $this->privacyService->logSensitiveAccess(
                    tenantId: (string) $tenantId,
                    userId: $request->user() ? (string) $request->user()->id : null,
                    subjectType: $subjectType,
                    subjectId: $subjectId,
                    dataCategory: $dataCategory,
                    action: $request->isMethod('GET') ? 'read' : 'export',
                    ipAddress: $request->ip(),
                );
            }
        }

        return $response;
    }
}
