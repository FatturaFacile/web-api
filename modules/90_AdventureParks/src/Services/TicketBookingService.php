<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Services;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AdventureParks\Domain\Enums\PaymentStatus;
use Modules\AdventureParks\Domain\Enums\PpeCategory;
use Modules\AdventureParks\Domain\Enums\TicketStatus;
use Modules\AdventureParks\Exceptions\AdventureParksException;
use Modules\AdventureParks\Models\PpeAsset;
use Modules\AdventureParks\Models\SlotTemplate;
use Modules\AdventureParks\Models\Ticket;
use Modules\AdventureParks\Models\TicketLine;

/**
 * Books a timed slot. The harness rows of the sizes involved are locked inside the transaction, so two desks (or the website) selling
 * the last harness of a size at the same moment cannot both succeed: the second one is refused with slot_sold_out.
 */
final class TicketBookingService
{
    private const string CodeAlphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly SlotAvailabilityService $availability,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  array{slot_id: string, slot_date: string, contact_name: string, contact_email?: string|null, contact_phone?: string|null, payment_status?: string|null, lines: list<array{size: string, quantity: int}>}  $data
     */
    public function book(array $data, ?string $userId): Ticket
    {
        $date = CarbonImmutable::parse($data['slot_date'])->startOfDay();
        $lines = $this->mergeLines($data['lines']);

        $ticketId = DB::transaction(function () use ($data, $date, $lines, $userId): string {
            /** @var SlotTemplate $slot */
            $slot = SlotTemplate::query()->findOrFail($data['slot_id']);

            if (! $slot->isOpenOn($date)) {
                throw AdventureParksException::refuse('slot_closed', 'The slot is not open on that day.', ['slot_id' => $slot->id, 'date' => $date->toDateString()]);
            }

            $assets = PpeAsset::query()
                ->where('category', PpeCategory::Harness->value)
                ->whereIn('size', array_keys($lines))
                ->lockForUpdate()
                ->get();

            $stock = $this->availability->stockFor($slot, $date, $assets);

            foreach ($lines as $size => $quantity) {
                $remaining = $stock[$size]['remaining'];

                if ($quantity > $remaining) {
                    throw AdventureParksException::refuse('slot_sold_out', 'Not enough harnesses of this size left in the slot.', [
                        'size' => $size,
                        'requested' => $quantity,
                        'remaining' => $remaining,
                    ]);
                }
            }

            $ticket = new Ticket([
                'tenant_id' => (string) $this->tenants->getTenantId(),
                'slot_template_id' => $slot->id,
                'booking_code' => $this->bookingCode($date),
                'public_token' => Str::random(40),
                'slot_date' => $date->toDateString(),
                'start_time' => $slot->start_time,
                'end_time' => $slot->end_time,
                'wristband_color' => $slot->wristband_color,
                'contact_name' => $data['contact_name'],
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'headcount' => array_sum($lines),
                'payment_status' => $data['payment_status'] ?? PaymentStatus::Pending->value,
                'status' => TicketStatus::Booked->value,
                'created_by' => $userId,
            ]);
            $ticket->save();

            foreach ($lines as $size => $quantity) {
                (new TicketLine([
                    'tenant_id' => $ticket->tenant_id,
                    'ticket_id' => $ticket->id,
                    'size' => $size,
                    'quantity' => $quantity,
                    'created_by' => $userId,
                ]))->save();
            }

            return $ticket->id;
        });

        return $this->fresh($ticketId);
    }

    /**
     * @return Collection<int, Ticket>
     */
    public function list(?string $date, ?string $status): Collection
    {
        return Ticket::query()
            ->with(['slot', 'lines', 'participants.assignments.asset', 'waiver'])
            ->when($date, fn (Builder $query, string $value) => $query->whereDate('slot_date', $value))
            ->when($status, fn (Builder $query, string $value) => $query->where('status', $value))
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->orderBy('booking_code')
            ->get();
    }

    /**
     * Scanner lookup: the booking code or the public token (also as read from the QR code of the waiver).
     */
    public function findByCodeOrToken(string $value): Ticket
    {
        $value = trim($value);

        $ticket = Ticket::query()
            ->with(['slot', 'lines', 'participants.assignments.asset', 'waiver'])
            ->where(fn (Builder $query) => $query->where('booking_code', strtoupper($value))->orWhere('public_token', $value))
            ->first();

        if ($ticket === null) {
            throw AdventureParksException::refuse('ticket_not_found', 'No booking matches this code.', ['code' => $value], 404);
        }

        return $ticket;
    }

    public function fresh(string $ticketId): Ticket
    {
        return Ticket::query()->with(['slot', 'lines', 'participants.assignments.asset', 'waiver'])->findOrFail($ticketId);
    }

    /**
     * Same size on several lines adds up.
     *
     * @param  list<array{size: string, quantity: int}>  $lines
     * @return array<string, int>
     */
    private function mergeLines(array $lines): array
    {
        $merged = [];

        foreach ($lines as $line) {
            $merged[$line['size']] = ($merged[$line['size']] ?? 0) + (int) $line['quantity'];
        }

        return $merged;
    }

    private function bookingCode(CarbonImmutable $date): string
    {
        do {
            $suffix = '';

            for ($i = 0; $i < 5; $i++) {
                $suffix .= self::CodeAlphabet[random_int(0, strlen(self::CodeAlphabet) - 1)];
            }

            $code = 'PK-'.$date->format('ymd').'-'.$suffix;
        } while (Ticket::query()->withTrashed()->where('booking_code', $code)->exists());

        return $code;
    }
}
