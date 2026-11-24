<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Optional yearly limit on issued documents, expressed in points. A tenant with no limit is never counted.
 */
final class DocumentQuotaService
{
    public const string InvoicePoints = '1.0';

    public const string CommercialDocumentPoints = '0.1';

    /**
     * @return array{enabled: bool, limit: ?string, used: string, remaining: ?string, year: int}
     */
    public function usage(Tenant $tenant, ?int $year = null): array
    {
        $year ??= (int) now()->format('Y');
        $limit = $tenant->document_quota_points;
        $used = $this->usedPoints((string) $tenant->id, $year);

        return [
            'enabled' => $limit !== null,
            'limit' => $limit !== null ? (string) $limit : null,
            'used' => $used,
            'remaining' => $limit !== null ? bcsub((string) $limit, $used, 1) : null,
            'year' => $year,
        ];
    }

    /**
     * @throws ValidationException with a 422 when the document would go over the yearly limit
     */
    public function assertCanIssue(Tenant $tenant, string $points): void
    {
        if ($tenant->document_quota_points === null) {
            return;
        }

        $used = $this->usedPoints((string) $tenant->id, (int) now()->format('Y'));

        if (bccomp(bcadd($used, $points, 1), (string) $tenant->document_quota_points, 1) === 1) {
            throw ValidationException::withMessages(['document_quota' => __('sales.document_quota_exceeded', [
                'limit' => (string) $tenant->document_quota_points,
                'used' => $used,
            ])]);
        }
    }

    private function usedPoints(string $tenantId, int $year): string
    {
        $invoices = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereYear('date', $year)
            ->where('invoice_type', '!=', 'proforma')
            ->whereNull('deleted_at')
            ->count();

        $commercial = DB::table('commercial_documents')
            ->where('tenant_id', $tenantId)
            ->where('fiscal_year', $year)
            ->where('document_type', 'sale')
            ->count();

        return bcadd(
            bcmul((string) $invoices, self::InvoicePoints, 1),
            bcmul((string) $commercial, self::CommercialDocumentPoints, 1),
            1,
        );
    }
}
