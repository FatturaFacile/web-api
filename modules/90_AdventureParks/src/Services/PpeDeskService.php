<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AdventureParks\Domain\Enums\PpeCategory;
use Modules\AdventureParks\Domain\Enums\PpeSize;
use Modules\AdventureParks\Domain\Enums\TicketStatus;
use Modules\AdventureParks\Exceptions\AdventureParksException;
use Modules\AdventureParks\Models\PpeAsset;
use Modules\AdventureParks\Models\SafetyWaiver;
use Modules\AdventureParks\Models\Ticket;
use Modules\AdventureParks\Models\TicketParticipant;
use Modules\AdventureParks\Models\TicketPpeAssignment;

/**
 * The dressing desk: hands the equipment out against the barcode, sends the group on the course and takes the equipment back.
 * Equipment is handed out only after the waiver, never when the item is blocked (status, end of life, overdue EN 365 inspection).
 */
final class PpeDeskService
{
    public function __construct(private readonly TicketBookingService $tickets) {}

    public function assign(string $ticketId, string $participantId, string $barcode, ?string $userId): Ticket
    {
        $now = CarbonImmutable::now();

        /** @var AdventureParksException|null $refusal */
        $refusal = DB::transaction(function () use ($ticketId, $participantId, $barcode, $userId, $now): ?AdventureParksException {
            /** @var Ticket $ticket */
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticketId);
            $participants = TicketParticipant::query()->where('ticket_id', $ticket->id)->get();
            $ticket->assertWaiverCovers($participants->count());

            /** @var TicketParticipant|null $participant */
            $participant = $participants->firstWhere('id', $participantId);

            if ($participant === null) {
                throw AdventureParksException::refuse('participant_not_found', 'The participant is not on this ticket.', ['participant_id' => $participantId], 404);
            }

            /** @var PpeAsset|null $asset */
            $asset = PpeAsset::query()->where('serial_code', $barcode)->lockForUpdate()->first();

            if ($asset === null) {
                throw AdventureParksException::refuse('ppe_not_found', 'No PPE with this barcode is registered.', ['barcode' => $barcode]);
            }

            $reason = $asset->blockedReason($now);

            if ($reason !== null) {
                // An item that ran out of life or inspection is blocked for good: the status change must survive the refusal.
                $asset->syncStatus($now);
                $asset->save();

                return $asset->blockedException($reason);
            }

            if ($asset->size !== PpeSize::Universal->value && $asset->size !== $participant->size) {
                throw AdventureParksException::refuse('ppe_size_mismatch', 'The size of the PPE does not fit the participant.', [
                    'serial_code' => $asset->serial_code,
                    'ppe_size' => $asset->size,
                    'participant_size' => $participant->size,
                ]);
            }

            $alreadyHas = TicketPpeAssignment::query()
                ->where('participant_id', $participant->id)
                ->whereNull('returned_at')
                ->whereHas('asset', fn ($query) => $query->where('category', $asset->category))
                ->exists();

            if ($alreadyHas) {
                throw AdventureParksException::refuse('ppe_already_assigned', 'The participant already holds equipment of this category.', ['category' => $asset->category]);
            }

            $asset->assign($now);
            $asset->save();

            (new TicketPpeAssignment([
                'tenant_id' => $ticket->tenant_id,
                'ticket_id' => $ticket->id,
                'participant_id' => $participant->id,
                'ppe_asset_id' => $asset->id,
                'assigned_at' => $now,
                'created_by' => $userId,
            ]))->save();

            if ($ticket->statusEnum() === TicketStatus::WaiverSigned && $this->everyoneHasHarness($ticket, $participants->pluck('id')->all())) {
                $ticket->equip();
                $ticket->save();
            }

            return null;
        });

        if ($refusal !== null) {
            throw $refusal;
        }

        return $this->tickets->fresh($ticketId);
    }

    public function startCourse(string $ticketId): Ticket
    {
        DB::transaction(function () use ($ticketId): void {
            /** @var Ticket $ticket */
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticketId);
            $ticket->startCourse();
            $ticket->save();
        });

        return $this->tickets->fresh($ticketId);
    }

    public function noShow(string $ticketId): Ticket
    {
        DB::transaction(function () use ($ticketId): void {
            /** @var Ticket $ticket */
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticketId);
            $ticket->markNoShow();
            $ticket->save();
        });

        return $this->tickets->fresh($ticketId);
    }

    /**
     * Takes the equipment back (every open assignment when no barcode is given). Each item goes back to the stock with one more
     * rental cycle and the hours of use (by default the length of the slot); the ticket is RETURNED once nothing is left out.
     *
     * @param  list<string>|null  $barcodes
     */
    public function returnPpe(string $ticketId, ?array $barcodes, ?float $usageHours): Ticket
    {
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($ticketId, $barcodes, $usageHours, $now): void {
            /** @var Ticket $ticket */
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticketId);
            $hours = $usageHours ?? $ticket->window()->durationMinutes() / 60;

            $open = TicketPpeAssignment::query()->where('ticket_id', $ticket->id)->whereNull('returned_at')->with('asset')->get();

            if ($barcodes !== null && $barcodes !== []) {
                $serials = $open->map(fn (TicketPpeAssignment $assignment): ?string => $assignment->asset?->serial_code)->filter()->all();

                foreach ($barcodes as $barcode) {
                    if (! in_array($barcode, $serials, true)) {
                        throw AdventureParksException::refuse('ppe_not_found', 'This PPE is not out on the ticket.', ['barcode' => $barcode]);
                    }
                }

                $open = $open->filter(fn (TicketPpeAssignment $assignment): bool => in_array($assignment->asset?->serial_code, $barcodes, true));
            }

            foreach ($open as $assignment) {
                /** @var PpeAsset $asset */
                $asset = PpeAsset::query()->lockForUpdate()->findOrFail($assignment->ppe_asset_id);
                $asset->release($hours, $now);
                $asset->save();

                $assignment->close($now, $hours);
                $assignment->save();
            }

            $stillOut = TicketPpeAssignment::query()->where('ticket_id', $ticket->id)->whereNull('returned_at')->exists();
            $hadEquipment = TicketPpeAssignment::query()->where('ticket_id', $ticket->id)->exists();

            if (! $stillOut && $hadEquipment && $ticket->canTransitionTo(TicketStatus::Returned)) {
                $ticket->markReturned();
                $ticket->save();
            }
        });

        return $this->tickets->fresh($ticketId);
    }

    public function completeBriefing(string $ticketId, string $instructorId): Ticket
    {
        /** @var Ticket $ticket */
        $ticket = Ticket::query()->findOrFail($ticketId);

        /** @var SafetyWaiver|null $waiver */
        $waiver = SafetyWaiver::query()->where('ticket_id', $ticket->id)->first();

        if ($waiver === null) {
            throw AdventureParksException::refuse('waiver_missing', 'The briefing follows the signed waiver.', ['booking_code' => $ticket->booking_code]);
        }

        $waiver->completeBriefing($instructorId, now());
        $waiver->save();

        return $this->tickets->fresh($ticketId);
    }

    /**
     * @param  list<string>  $participantIds
     */
    private function everyoneHasHarness(Ticket $ticket, array $participantIds): bool
    {
        $equipped = TicketPpeAssignment::query()
            ->where('ticket_id', $ticket->id)
            ->whereNull('returned_at')
            ->whereHas('asset', fn ($query) => $query->where('category', PpeCategory::Harness->value))
            ->pluck('participant_id')
            ->unique()
            ->all();

        return array_diff($participantIds, $equipped) === [];
    }
}
