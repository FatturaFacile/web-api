<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\ItalianVat\Exceptions\ItalianVatException;

/**
 * A movement of goods held, processed or sold on behalf of a third party (register of goods on account of third parties,
 * art. 39 DPR 633/1972). Locked by a definitive print like every other register line.
 *
 * @property string $section_id
 * @property int $year
 * @property int $protocol_number
 * @property string|null $print_id
 * @property CarbonImmutable $movement_date
 * @property string $movement_type
 * @property string $contact_id
 * @property string $description
 * @property string $unit
 * @property string $quantity
 * @property string $value
 * @property string|null $document_number
 * @property CarbonImmutable|null $document_date
 * @property string|null $notes
 */
final class ThirdPartyGoodsMovement extends BaseEnterpriseModel
{
    public const string RECEIVED = 'received';

    public const string RETURNED = 'returned';

    public const string PROCESSED = 'processed';

    public const string SOLD = 'sold';

    /** Movements that bring goods into the custody of the company. */
    public const array INBOUND = [self::RECEIVED];

    /** Movements that take goods out of the custody of the company. */
    public const array OUTBOUND = [self::RETURNED, self::PROCESSED, self::SOLD];

    protected $table = 'it_third_party_goods_movements';

    protected static function booted(): void
    {
        parent::booted();

        self::updating(static function (self $movement): void {
            if ($movement->getOriginal('print_id') !== null) {
                throw ItalianVatException::locked('register_entry_locked', ['protocol' => $movement->protocol_number]);
            }
        });

        self::deleting(static function (self $movement): void {
            if ($movement->print_id !== null) {
                throw ItalianVatException::locked('register_entry_locked', ['protocol' => $movement->protocol_number]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'protocol_number' => 'integer',
            'movement_date' => 'immutable_date',
            'document_date' => 'immutable_date',
            'quantity' => 'decimal:3',
            'value' => 'decimal:2',
        ];
    }
}
