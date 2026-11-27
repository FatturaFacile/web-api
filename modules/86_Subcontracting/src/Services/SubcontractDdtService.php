<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Services;

use DomainException;
use InvalidArgumentException;
use Modules\Logistics\Enums\DeliveryNoteType;
use Modules\Logistics\Services\DeliveryNoteService;
use Modules\Subcontracting\Exceptions\SubcontractingException;

/**
 * Emits the DDTs of the subcontracting flows through the Logistics DDT lifecycle (Mod 040): "Conto lavoro" for the material that
 * goes out, "Reso da terzista" for unused material that comes back, "Reso lavorato" for processed customer goods. Issuing the DDT
 * moves the stock between the two warehouses of the document, so the caller never moves the same goods twice.
 */
final class SubcontractDdtService
{
    public function __construct(private readonly DeliveryNoteService $notes) {}

    /**
     * @param  array<string, mixed>  $header  supplier_id or contact_id, origin_warehouse_id, destination_warehouse_id, date, carrier and notes
     * @param  list<array{product_id: string, description: string, quantity: float, uom?: string|null, batch_number?: string|null, serial_number?: string|null}>  $items
     * @return array{id: string, number: string, date: string, reason: string}
     */
    public function issue(DeliveryNoteType $type, array $header, array $items): array
    {
        try {
            $note = $this->notes->create(array_merge($header, ['type' => $type->value]), $items);
            $note = $this->notes->issue($note);
        } catch (DomainException|InvalidArgumentException $e) {
            throw new SubcontractingException('ddt_failed', 422, ['reason' => $e->getMessage()]);
        }

        return [
            'id' => (string) $note->id,
            'number' => (string) $note->ddt_number,
            'date' => $note->date->toDateString(),
            'reason' => (string) $note->transport_reason_code,
        ];
    }
}
