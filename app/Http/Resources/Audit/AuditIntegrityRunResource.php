<?php

declare(strict_types=1);

namespace App\Http\Resources\Audit;

use Enterprise\AuditLedger\Application\Queries\GetIntegrityStatusQuery;
use Enterprise\AuditLedger\Models\IntegrityVerificationRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property IntegrityVerificationRun $resource
 */
final class AuditIntegrityRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return GetIntegrityStatusQuery::run($this->resource);
    }
}
