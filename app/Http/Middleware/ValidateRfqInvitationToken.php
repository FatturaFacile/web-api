<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Procurement\Rfq\Enums\RfqStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the cryptographic magic-link token for external supplier RFQ portal requests.
 * Returns 401 when unknown and 410 Gone when expired or cancelled, binding TenantContext safely.
 */
final readonly class ValidateRfqInvitationToken
{
    use ApiResponse;

    public function __construct(
        private TenantContext $tenantContext,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = (string) $request->route('token', '');
        $trimmed = trim($rawToken);

        if ($trimmed === '') {
            return $this->problemDetailsResponse(
                title: 'Unauthorized',
                detail: 'RFQ invitation token is missing or invalid.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        $tokenHash = hash('sha256', $trimmed);

        /** @var RfqSupplierInvitation|null $invitation */
        $invitation = RfqSupplierInvitation::withoutGlobalScopes()
            ->where('access_token_hash', $tokenHash)
            ->first();

        if ($invitation === null) {
            return $this->problemDetailsResponse(
                title: 'Unauthorized',
                detail: 'RFQ invitation token is invalid.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        /** @var RequestForQuotation|null $rfq */
        $rfq = RequestForQuotation::withoutGlobalScopes()
            ->whereKey($invitation->rfq_id)
            ->first();

        if ($rfq === null || $rfq->statusEnum() === RfqStatus::Cancelled) {
            return $this->problemDetailsResponse(
                title: 'Gone',
                detail: 'This Request for Quotation has been cancelled or is no longer available.',
                status: Response::HTTP_GONE,
            );
        }

        if ($invitation->token_expires_at === null || CarbonImmutable::now('UTC')->gt($invitation->token_expires_at)) {
            return $this->problemDetailsResponse(
                title: 'Gone',
                detail: 'This RFQ invitation link has expired.',
                status: Response::HTTP_GONE,
            );
        }

        $tenant = Tenant::query()->find($invitation->tenant_id);
        if ($tenant === null) {
            return $this->problemDetailsResponse(
                title: 'Unauthorized',
                detail: 'Tenant associated with this invitation could not be resolved.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        $this->tenantContext->setTenant($tenant);
        $request->attributes->set('rfq_invitation_id', (string) $invitation->id);
        $request->attributes->set('rfq_id', (string) $rfq->id);

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
