<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\ItalianVat\Exceptions\ItalianVatException;

/**
 * The definitive print (stampa definitiva) of a register range with its virtual numbering stamp. VAT registers carry no
 * stamp duty (art. 39 DPR 633/1972) but must be numbered page by page: the stamp reference, the page range and the content
 * hash are the audit trail that proves the printed lines were not altered afterwards. A print is immutable.
 *
 * @property string $section_id
 * @property int $year
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property int $first_protocol
 * @property int $last_protocol
 * @property int $entries_count
 * @property int $page_from
 * @property int $page_to
 * @property string $total_taxable
 * @property string $total_vat
 * @property string $total_deductible_vat
 * @property string $content_hash
 * @property string $stamp_reference
 * @property CarbonImmutable $printed_at
 * @property string|null $printed_by
 */
final class VatRegisterPrint extends BaseEnterpriseModel
{
    protected $table = 'it_vat_register_prints';

    protected static function booted(): void
    {
        parent::booted();

        self::updating(static function (): void {
            throw ItalianVatException::locked('print_immutable');
        });

        self::deleting(static function (): void {
            throw ItalianVatException::locked('print_immutable');
        });
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'first_protocol' => 'integer',
            'last_protocol' => 'integer',
            'entries_count' => 'integer',
            'page_from' => 'integer',
            'page_to' => 'integer',
            'total_taxable' => 'decimal:2',
            'total_vat' => 'decimal:2',
            'total_deductible_vat' => 'decimal:2',
            'printed_at' => 'immutable_datetime',
        ];
    }
}
