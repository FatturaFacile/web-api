<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Modules\Associations\Models\EnrolmentForm;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public enrolment routes: the random token of the link is the only thing that selects the tenant. A tenant id sent by
 * the client is never read.
 */
final class ResolveEnrolmentForm
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $form = EnrolmentForm::withoutGlobalScopes()->whereNull('deleted_at')->where('public_token', (string) $request->route('token'))->first();
        $tenant = $form === null ? null : Tenant::query()->whereKey($form->tenant_id)->where('status', 'active')->first();

        if ($form === null || $tenant === null) {
            return $this->problemDetailsResponse(title: 'Not Found', detail: 'This enrolment link does not exist.', status: Response::HTTP_NOT_FOUND);
        }

        if ($tenant->is_maintenance) {
            return $this->problemDetailsResponse(title: __('backup.maintenance.title'), detail: __('backup.maintenance.detail'), status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $previous = $this->tenantContext->isExplicit() ? $this->tenantContext->getTenant() : null;
        $this->tenantContext->setTenant($tenant);
        $request->attributes->set('enrolmentForm', $form);

        try {
            return $next($request);
        } finally {
            $previous === null ? $this->tenantContext->clear() : $this->tenantContext->setTenant($previous);
        }
    }
}
