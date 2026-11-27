<?php

declare(strict_types=1);

namespace Modules\Esterometro\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Per-tenant configuration for Italian Esterometro & Reverse Charge self-invoicing (TD16–TD28).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $auto_generate_mode
 * @property bool $auto_post_vat_registers
 * @property bool $auto_post_journal
 * @property string $sales_vat_section_code
 * @property string $purchase_vat_section_code
 * @property string $numbering_prefix
 * @property int $numbering_padding
 * @property string $default_recipient_code
 * @property bool $use_tenant_sdi_code
 * @property string $signature_format
 * @property string|null $clearing_account_id
 * @property string|null $non_deductible_account_id
 */
final class SelfInvoiceSetting extends BaseEnterpriseModel
{
    protected $table = 'self_invoice_settings';

    protected $fillable = [
        'tenant_id',
        'auto_generate_mode',
        'auto_post_vat_registers',
        'auto_post_journal',
        'sales_vat_section_code',
        'purchase_vat_section_code',
        'numbering_prefix',
        'numbering_padding',
        'default_recipient_code',
        'use_tenant_sdi_code',
        'signature_format',
        'clearing_account_id',
        'non_deductible_account_id',
    ];

    protected function casts(): array
    {
        return [
            'auto_post_vat_registers' => 'boolean',
            'auto_post_journal' => 'boolean',
            'numbering_padding' => 'integer',
            'use_tenant_sdi_code' => 'boolean',
        ];
    }
}
