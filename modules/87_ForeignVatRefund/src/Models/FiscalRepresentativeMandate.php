<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A mandate of a fiscal representative in a foreign country, with the certificate of taxable status (attestazione di soggettività passiva).
 *
 * @property string $country_code
 * @property string $representative_name
 * @property string|null $representative_tax_id
 * @property string|null $address
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon $mandate_start_date
 * @property Carbon|null $mandate_end_date
 * @property string|null $power_of_attorney_document_path
 * @property string|null $certificate_of_taxable_status_path
 * @property Carbon|null $certificate_expiry_date
 * @property bool $is_active
 */
final class FiscalRepresentativeMandate extends BaseEnterpriseModel
{
    protected $table = 'fiscal_representative_mandates';

    protected function casts(): array
    {
        return [
            'mandate_start_date' => 'date',
            'mandate_end_date' => 'date',
            'certificate_expiry_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function coversDate(Carbon $date): bool
    {
        return $this->is_active
            && $this->mandate_start_date->lte($date)
            && ($this->mandate_end_date === null || $this->mandate_end_date->gte($date));
    }

    public function hasValidCertificate(Carbon $on): bool
    {
        return $this->certificate_of_taxable_status_path !== null
            && $this->certificate_of_taxable_status_path !== ''
            && ($this->certificate_expiry_date === null || $this->certificate_expiry_date->gte($on));
    }
}
