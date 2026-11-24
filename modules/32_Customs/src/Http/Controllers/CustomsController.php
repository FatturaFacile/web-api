<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * What the customs controllers share.
 */
abstract class CustomsController extends Controller
{
    public function __construct(protected readonly TenantContext $tenantContext) {}

    protected function tenant(): Tenant
    {
        return $this->tenantContext->getTenant();
    }

    protected function actorId(Request $request): ?string
    {
        return $request->user()?->getKey() === null ? null : (string) $request->user()->getKey();
    }

    protected function perPage(Request $request, int $default = 25, int $maximum = 100): int
    {
        return max(1, min($request->integer('per_page', $default), $maximum));
    }

    protected function fileResponse(string $content, string $fileName, string $contentType): Response
    {
        return response($content, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', Str::ascii($fileName)).'"',
        ]);
    }
}
