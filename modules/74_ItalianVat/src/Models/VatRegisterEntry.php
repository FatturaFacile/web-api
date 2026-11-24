<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\ItalianVat\Exceptions\ItalianVatException;

/**
 * One line of an Italian VAT register. Amounts are signed: a credit note is stored with negative amounts. Once a definitive
 * print covers the line (`print_id` set) it can no longer be changed or deleted: a correction is a new, opposite entry.
 *
 * @property string $section_id
 * @property string $register_type
 * @property int $year
 * @property int $protocol_number
 * @property CarbonImmutable $entry_date
 * @property CarbonImmutable|null $document_date
 * @property string|null $document_number
 * @property string $document_kind
 * @property string|null $document_type_code
 * @property string|null $contact_id
 * @property string|null $counterparty_name
 * @property string|null $counterparty_vat
 * @property string $taxable_amount
 * @property string $vat_rate
 * @property string $vat_amount
 * @property string|null $vat_nature
 * @property string $gross_amount
 * @property string $deduction_percentage
 * @property string $deductible_vat
 * @property string $non_deductible_vat
 * @property string|null $non_deductible_reason
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $print_id
 * @property string|null $notes
 */
final class VatRegisterEntry extends BaseEnterpriseModel
{
    protected $table = 'it_vat_register_entries';

    protected static function booted(): void
    {
        parent::booted();

        self::updating(static function (self $entry): void {
            if ($entry->getOriginal('print_id') !== null) {
                throw ItalianVatException::locked('register_entry_locked', ['protocol' => $entry->protocol_number]);
            }
        });

        self::deleting(static function (self $entry): void {
            if ($entry->print_id !== null) {
                throw ItalianVatException::locked('register_entry_locked', ['protocol' => $entry->protocol_number]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'protocol_number' => 'integer',
            'entry_date' => 'immutable_date',
            'document_date' => 'immutable_date',
            'taxable_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'deduction_percentage' => 'decimal:2',
            'deductible_vat' => 'decimal:2',
            'non_deductible_vat' => 'decimal:2',
        ];
    }

    public function isLocked(): bool
    {
        return $this->print_id !== null;
    }
}
