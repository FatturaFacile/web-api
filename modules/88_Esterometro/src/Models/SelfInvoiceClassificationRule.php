<?php

declare(strict_types=1);

namespace Modules\Esterometro\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Modules\Esterometro\Domain\Enums\SelfInvoiceDocumentType;
use Modules\Esterometro\Domain\Enums\TransactionNature;

/**
 * Deterministic classification rule mapping purchase invoice attributes to FatturaPA TD16–TD28.
 * Global rows (tenant_id = null) provide statutory defaults; tenant rows override or extend them.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $code
 * @property string $name
 * @property SelfInvoiceDocumentType $document_type
 * @property string $supplier_region
 * @property string|null $supplier_country_code
 * @property TransactionNature|string $transaction_nature
 * @property string|null $source_vat_nature_prefix
 * @property string $default_vat_rate
 * @property string|null $default_vat_nature
 * @property string $default_deductibility_percent
 * @property string $soggetto_emittente
 * @property int $priority
 * @property string|null $legal_reference
 * @property bool $is_active
 */
final class SelfInvoiceClassificationRule extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'self_invoice_classification_rules';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'document_type',
        'supplier_region',
        'supplier_country_code',
        'transaction_nature',
        'source_vat_nature_prefix',
        'default_vat_rate',
        'default_vat_nature',
        'default_deductibility_percent',
        'soggetto_emittente',
        'priority',
        'legal_reference',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => SelfInvoiceDocumentType::class,
            'default_vat_rate' => 'decimal:2',
            'default_deductibility_percent' => 'decimal:2',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
