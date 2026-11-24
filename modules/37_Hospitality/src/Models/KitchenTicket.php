<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

/**
 * A ticket for one station: the items of a course fired together.
 *
 * @property string $id
 * @property string $order_id
 * @property string $station
 * @property int $course
 * @property string $status
 * @property list<array{order_item_id: string, description: string, quantity: string, notes: ?string}> $lines
 */
final class KitchenTicket extends HospitalityRecord
{
    protected $table = 'hosp_kitchen_tickets';

    protected function casts(): array
    {
        return ['course' => 'integer', 'lines' => 'array', 'ready_at' => 'immutable_datetime'];
    }
}
