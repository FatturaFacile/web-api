<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\DocumentSequence;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class DocumentNumberingService
{
    /**
     * Default number prefixes per document type.
     *
     * @var array<string, string>
     */
    private const DEFAULT_PREFIXES = [
        'invoice' => 'INV-',
        'credit_note' => 'CN-',
        'proforma' => 'PF-',
        'quote' => 'QT-',
        'sales_order' => 'SO-',
        'delivery_note' => 'DDT-',
        'work_order' => 'WO-',
        'pos_receipt' => 'REC-',
        'purchase_order' => 'PO-',
        'goods_receipt' => 'GR-',
    ];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Atomically reserve the next gapless number for the document type and fiscal year.
     *
     * @return array{number: string, sequential_number: int, fiscal_year: int}
     */
    public function next(string $documentType, ?int $fiscalYear = null): array
    {
        $fiscalYear ??= (int) now()->format('Y');
        $tenantId = $this->tenantContext->getTenantId();

        return DB::transaction(function () use ($documentType, $fiscalYear, $tenantId): array {
            $sequence = DocumentSequence::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('document_type', $documentType)
                ->where('fiscal_year', $fiscalYear)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = DocumentSequence::create([
                    'tenant_id' => $tenantId,
                    'document_type' => $documentType,
                    'fiscal_year' => $fiscalYear,
                    'prefix' => self::DEFAULT_PREFIXES[$documentType] ?? strtoupper(substr($documentType, 0, 3)).'-',
                    'last_number' => 0,
                    'padding' => 4,
                ]);
            }

            $sequence->last_number++;
            $sequence->save();

            return [
                'number' => sprintf('%s%d-%s', $sequence->prefix, $fiscalYear, str_pad((string) $sequence->last_number, $sequence->padding, '0', STR_PAD_LEFT)),
                'sequential_number' => $sequence->last_number,
                'fiscal_year' => $fiscalYear,
            ];
        });
    }
}
