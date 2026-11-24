<?php

declare(strict_types=1);

namespace Modules\SwissVat\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted audit record of a SuisseTax eCH-0217 XML export and its XSD validation outcome.
 *
 * @property string $swiss_vat_return_id
 * @property string $accounting_method
 * @property string $period_label
 * @property string $schema_version
 * @property string $file_name
 * @property string $sha256_checksum
 * @property bool $xsd_valid
 * @property bool $quadratura_passed
 * @property list<string>|null $validation_errors
 * @property string $xml_payload
 * @property string|null $exported_by
 * @property CarbonImmutable $exported_at
 */
final class SwissVatXmlExport extends BaseEnterpriseModel
{
    protected $table = 'swiss_vat_xml_exports';

    protected function casts(): array
    {
        return [
            'xsd_valid' => 'boolean',
            'quadratura_passed' => 'boolean',
            'validation_errors' => 'array',
            'exported_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SwissVatReturn, $this>
     */
    public function vatReturn(): BelongsTo
    {
        return $this->belongsTo(SwissVatReturn::class, 'swiss_vat_return_id');
    }
}
