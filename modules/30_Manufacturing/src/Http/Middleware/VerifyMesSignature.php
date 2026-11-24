<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Middleware;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Manufacturing\Support\MesHmac;
use Modules\Manufacturing\Support\MesProblem;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the MES Core, a service-to-service caller with no user: header
 * `X-Mes-Signature: t=<unix>,kid=<key id>,v1=<hex>`, where v1 = HMAC-SHA256(secret[kid], t + "." + raw body).
 *
 * - The timestamp must be within the configured tolerance of the server clock (replay window).
 * - Keys are rotated with `kid`: several keys can be configured at the same time.
 * - A key is bound to the tenants it may address. The tenant of the request comes from the `X-Tenant-Id` header and is
 *   refused with 403 unless the key lists it, so a valid signature never reaches another tenant's data.
 *
 * Failures are RFC 9457 problems. The tenant context is set for the request and restored afterwards.
 */
final class VerifyMesSignature
{
    public const string SignatureHeader = 'X-Mes-Signature';

    public const string TenantHeader = 'X-Tenant-Id';

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Verifies the signature and the tenant scope, then runs the request in the tenant context.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $parts = $this->parse((string) $request->header(self::SignatureHeader, ''));

        if ($parts === null) {
            return MesProblem::response(401, 'signature.invalid', 'Invalid signature', 'The X-Mes-Signature header is missing or malformed.');
        }

        $key = config('manufacturing_mes.keys.'.$parts['kid']);

        if (! is_array($key) || ! is_string($key['secret'] ?? null) || $key['secret'] === '') {
            return MesProblem::response(401, 'signature.invalid', 'Invalid signature', 'The signature key is unknown.');
        }

        if (abs(now()->getTimestamp() - $parts['t']) > (int) config('manufacturing_mes.signature_tolerance_seconds')) {
            return MesProblem::response(401, 'signature.expired', 'Signature expired', 'The signature timestamp is outside the accepted window.');
        }

        $expected = MesHmac::compute($key['secret'], $parts['t'], $request->getContent());

        if (! hash_equals($expected, $parts['v1'])) {
            return MesProblem::response(401, 'signature.invalid', 'Invalid signature', 'The signature does not match the request.');
        }

        $tenantId = (string) $request->header(self::TenantHeader, '');

        if (! Str::isUuid($tenantId)) {
            return MesProblem::response(400, 'tenant.missing', 'Tenant required', 'The X-Tenant-Id header must carry the tenant id.');
        }

        $allowed = array_map('strtolower', array_filter((array) ($key['tenants'] ?? []), 'is_string'));
        $tenant = in_array(strtolower($tenantId), $allowed, true) ? Tenant::query()->find($tenantId) : null;

        if ($tenant === null) {
            return MesProblem::response(403, 'tenant.forbidden', 'Tenant forbidden', 'The signing key may not address this tenant.');
        }

        $request->attributes->set('mes_tenant_id', (string) $tenant->id);

        return $this->tenantContext->runWithTenant($tenant, static fn (): Response => $next($request));
    }

    /**
     * @return array{t: int, kid: string, v1: string}|null
     */
    private function parse(string $header): ?array
    {
        $fields = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $fields[$name] = $value;
        }

        if (! ctype_digit($fields['t'] ?? '') || ($fields['kid'] ?? '') === '' || ! ctype_xdigit($fields['v1'] ?? '')) {
            return null;
        }

        return ['t' => (int) $fields['t'], 'kid' => $fields['kid'], 'v1' => strtolower($fields['v1'])];
    }
}
