<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\PassengerTransport\Domain\Enums\BookingStatus;
use Modules\PassengerTransport\Domain\Enums\ExchangeDirection;
use Modules\PassengerTransport\Domain\Support\Dec;
use Modules\PassengerTransport\Exceptions\PassengerTransportException;
use Modules\PassengerTransport\Models\PassengerBooking;

/**
 * Service exchange between partner NCC carriers (scambio servizio con collega): a trip ceded to a colleague (the colleague earns the
 * payout, the company keeps the difference) or acquired from one (the colleague keeps a commission). The ledger gives the net balance
 * to settle with each partner.
 */
final class SubcontractService
{
    /**
     * Cedes the trip to a partner carrier for a payout; the company keeps the rest of the fare as commission.
     */
    public function cede(PassengerBooking $booking, string $carrierId, string $payoutAmount, ?string $userId): PassengerBooking
    {
        $this->assertOpen($booking);

        if (Dec::cmp($payoutAmount, '0') < 0 || Dec::cmp($payoutAmount, $booking->agreed_price_net) > 0) {
            throw new PassengerTransportException('subcontract_payout_invalid', 422, ['agreed' => $booking->agreed_price_net]);
        }

        return DB::transaction(function () use ($booking, $carrierId, $payoutAmount, $userId): PassengerBooking {
            $booking->update([
                'exchange_direction' => ExchangeDirection::Ceded->value,
                'subcontracted_carrier_id' => $carrierId,
                'subcontractor_payout_amount' => Dec::round($payoutAmount),
                'assignor_commission_amount' => Dec::round(Dec::sub($booking->agreed_price_net, $payoutAmount)),
                'assigned_vehicle_id' => null,
                'assigned_driver_id' => null,
                'status' => $booking->status === BookingStatus::Draft ? BookingStatus::Draft->value : BookingStatus::Confirmed->value,
                'updated_by' => $userId,
            ]);

            return $booking->refresh();
        });
    }

    /**
     * Takes over a trip a partner carrier assigned to the company for a commission; the rest of the fare is the payout the company receives.
     */
    public function acquire(PassengerBooking $booking, string $assignorId, string $commissionAmount, ?string $userId): PassengerBooking
    {
        $this->assertOpen($booking);

        if (Dec::cmp($commissionAmount, '0') < 0 || Dec::cmp($commissionAmount, $booking->agreed_price_net) > 0) {
            throw new PassengerTransportException('subcontract_commission_invalid', 422, ['agreed' => $booking->agreed_price_net]);
        }

        return DB::transaction(function () use ($booking, $assignorId, $commissionAmount, $userId): PassengerBooking {
            $booking->update([
                'exchange_direction' => ExchangeDirection::Acquired->value,
                'subcontracted_carrier_id' => $assignorId,
                'assignor_commission_amount' => Dec::round($commissionAmount),
                'subcontractor_payout_amount' => Dec::round(Dec::sub($booking->agreed_price_net, $commissionAmount)),
                'updated_by' => $userId,
            ]);

            return $booking->refresh();
        });
    }

    public function release(PassengerBooking $booking, ?string $userId): PassengerBooking
    {
        $this->assertOpen($booking);
        $booking->update([
            'exchange_direction' => ExchangeDirection::None->value,
            'subcontracted_carrier_id' => null,
            'subcontractor_payout_amount' => '0.00',
            'assignor_commission_amount' => '0.00',
            'updated_by' => $userId,
        ]);

        return $booking->refresh();
    }

    /**
     * The ceded and acquired trips with the balance by partner. A positive net balance is owed to the company.
     *
     * @return array{rows: list<array<string, mixed>>, partners: list<array<string, mixed>>}
     */
    public function ledger(?string $period = null, ?string $carrierId = null): array
    {
        $bookings = PassengerBooking::query()
            ->with('carrier:id,company_name')
            ->where('exchange_direction', '!=', ExchangeDirection::None->value)
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->when($carrierId, fn ($query, $id) => $query->where('subcontracted_carrier_id', $id))
            ->when($period, function ($query, string $month): void {
                $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
                $query->whereBetween('pickup_datetime', [$start, $start->endOfMonth()]);
            })
            ->orderBy('pickup_datetime')
            ->get();

        $rows = [];
        $partners = [];

        foreach ($bookings as $booking) {
            $partnerId = (string) $booking->subcontracted_carrier_id;
            $ceded = $booking->exchange_direction === ExchangeDirection::Ceded;
            $rows[] = [
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'direction' => $booking->exchange_direction->value,
                'carrier_id' => $partnerId,
                'carrier_name' => $booking->carrier?->company_name,
                'pickup_datetime' => $booking->pickup_datetime->toIso8601String(),
                'route' => $booking->pickup_address.' → '.$booking->dropoff_address,
                'agreed_price_net' => $booking->agreed_price_net,
                'payout' => $booking->subcontractor_payout_amount,
                'commission' => $booking->assignor_commission_amount,
                'currency' => $booking->currency,
            ];

            $partner = $partners[$partnerId] ?? ['carrier_id' => $partnerId, 'carrier_name' => $booking->carrier?->company_name, 'currency' => $booking->currency, 'ceded_count' => 0, 'ceded_payout' => '0', 'ceded_commission_kept' => '0', 'acquired_count' => 0, 'acquired_payout' => '0', 'acquired_commission_owed' => '0', 'net_balance' => '0'];

            if ($ceded) {
                $partner['ceded_count']++;
                $partner['ceded_payout'] = Dec::add($partner['ceded_payout'], $booking->subcontractor_payout_amount);
                $partner['ceded_commission_kept'] = Dec::add($partner['ceded_commission_kept'], $booking->assignor_commission_amount);
            } else {
                $partner['acquired_count']++;
                $partner['acquired_payout'] = Dec::add($partner['acquired_payout'], $booking->subcontractor_payout_amount);
                $partner['acquired_commission_owed'] = Dec::add($partner['acquired_commission_owed'], $booking->assignor_commission_amount);
            }

            $partner['net_balance'] = Dec::sub($partner['acquired_payout'], $partner['ceded_payout']);
            $partners[$partnerId] = $partner;
        }

        return [
            'rows' => $rows,
            'partners' => array_values(array_map(static fn (array $partner): array => [
                ...$partner,
                'ceded_payout' => Dec::round($partner['ceded_payout']),
                'ceded_commission_kept' => Dec::round($partner['ceded_commission_kept']),
                'acquired_payout' => Dec::round($partner['acquired_payout']),
                'acquired_commission_owed' => Dec::round($partner['acquired_commission_owed']),
                'net_balance' => Dec::round($partner['net_balance']),
            ], $partners)),
        ];
    }

    private function assertOpen(PassengerBooking $booking): void
    {
        if (! $booking->isEditable()) {
            throw new PassengerTransportException('booking_locked', 409);
        }
    }
}
