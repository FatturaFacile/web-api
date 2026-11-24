<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A sectional of a VAT register (sezionale): ordinary invoices, deferred invoices, credit notes, self-invoices...
 * Every sectional numbers its entries progressively within the calendar year.
 *
 * @property string $register_type
 * @property string $code
 * @property string $name
 * @property string|null $document_kind
 * @property bool $is_active
 */
final class VatSection extends BaseEnterpriseModel
{
    protected $table = 'it_vat_sections';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
