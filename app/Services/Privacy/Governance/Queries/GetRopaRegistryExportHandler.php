<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance\Queries;

use App\Models\Privacy\ProcessingActivityRecordRopa;
use App\Models\Tenant;
use App\Services\Privacy\Governance\RopaExportService;
use Enterprise\MultiTenant\Scopes\TenantScope;

final readonly class GetRopaRegistryExportHandler
{
    public function __construct(private RopaExportService $exports) {}

    /**
     * @return array{content: string, mime: string, filename: string}
     */
    public function handle(GetRopaRegistryExportQuery $query): array
    {
        $records = ProcessingActivityRecordRopa::withoutGlobalScope(TenantScope::class)->where('tenant_id', $query->tenantId)->orderBy('activity_code')->get();
        $company = (string) (Tenant::query()->whereKey($query->tenantId)->value('legal_name') ?? '');

        return $query->format === 'xlsx'
            ? ['content' => $this->exports->xlsx($records), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'filename' => 'ropa-registry.xlsx']
            : ['content' => $this->exports->pdf($records, $company), 'mime' => 'application/pdf', 'filename' => 'ropa-registry.pdf'];
    }
}
