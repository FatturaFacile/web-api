<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Concerns;

use Illuminate\Http\Request;
use Modules\Platform\Identity\Application\FieldPolicyResolver;

/**
 * For API resources: pass the payload of the resource through the field policies of the signed-in user. The masked and
 * hidden fields are listed in `meta.masked_fields[]` of the response by AppendMaskedFieldsMeta.
 *
 *     return $this->maskFields($request, 'hr.employee', [...]);
 */
trait MasksSensitiveFields
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function maskFields(Request $request, string $resource, array $payload): array
    {
        $companyId = $request->header('X-Company-Id');

        return app(FieldPolicyResolver::class)->apply($request->user(), $resource, $payload, is_string($companyId) && $companyId !== '' ? $companyId : null);
    }
}
