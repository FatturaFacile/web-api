<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\IndustrialLaundry\Contracts\LaundryDeliveryNoteWriterInterface;
use Modules\IndustrialLaundry\Domain\Enums\ScanDirection;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesDeliveryNoteItem;
use Modules\Logistics\Enums\DeliveryNoteType;
use Modules\Logistics\Enums\TransportReason;

/**
 * Writes the delivery note (DDT) of a tunnel gate scan in the canonical delivery notes of the invoicing module, numbered in the
 * delivery note series. The note is a draft: the dispatcher issues it with the load.
 */
final class SalesDeliveryNoteWriter implements LaundryDeliveryNoteWriterInterface
{
    public function __construct(private readonly DocumentNumberingService $numbering) {}

    public function write(
        string $tenantId,
        string $contactId,
        ScanDirection $direction,
        array $piecesByArticleType,
        ?string $grossWeightKg,
        ?string $netWeightKg,
        string $rollCageBarcode,
        Carbon $at,
        ?string $userId,
    ): array {
        $inbound = $direction === ScanDirection::InboundSoil;
        $type = $inbound ? DeliveryNoteType::ReturnFromCustomer : DeliveryNoteType::OutboundSale;
        $reason = TransportReason::Subcontracting;
        $number = $this->numbering->next($type->numberingSection(), $at->year, $tenantId)['number'];

        $note = SalesDeliveryNote::query()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'ddt_number' => $number,
            'date' => $at->toDateString(),
            'type' => $type->value,
            'transport_reason' => $reason->label(),
            'transport_reason_code' => $reason->value,
            'goods_appearance' => __('industrial_laundry.ddt.goods_appearance'),
            'packages_count' => 1,
            'gross_weight_kg' => $grossWeightKg,
            'net_weight_kg' => $netWeightKg,
            'departure_time' => $at,
            'show_prices' => false,
            'status' => 'draft',
            'shipping_status' => 'draft',
            'notes' => __($inbound ? 'industrial_laundry.ddt.note_inbound' : 'industrial_laundry.ddt.note_outbound', ['cage' => $rollCageBarcode]),
            'created_by' => $userId !== null && Str::isUuid($userId) ? $userId : null,
        ]);

        foreach ($piecesByArticleType as $articleType => $pieces) {
            SalesDeliveryNoteItem::query()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'delivery_note_id' => (string) $note->id,
                'description' => __('industrial_laundry.article_types.'.$articleType),
                'quantity' => (string) $pieces,
                'uom' => 'PZ',
            ]);
        }

        return ['id' => (string) $note->id, 'number' => $number];
    }
}
