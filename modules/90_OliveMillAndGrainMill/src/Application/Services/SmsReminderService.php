<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Services;

use App\Contracts\Sms\SmsDeliveryException;
use App\Contracts\Sms\SmsGateway;
use Illuminate\Support\Carbon;
use Modules\OliveMillAndGrainMill\Domain\Enums\DeliveryStatus;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;

/**
 * The hopper SMS to the farmer ("your lot enters the hopper in 45 minutes"): sent on request or by the scheduler when the slot is within the lead time.
 */
final class SmsReminderService
{
    public function __construct(private readonly SmsGateway $sms) {}

    /**
     * @throws OliveMillException phone_missing, sms_failed
     */
    public function send(OliveDelivery $delivery, ?int $minutes = null): OliveDelivery
    {
        $phone = $this->phoneOf($delivery);

        if ($phone === null) {
            throw OliveMillException::because('phone_missing');
        }

        $minutes ??= $this->leadMinutes();

        try {
            $this->sms->send($phone, __('olive_mill.sms.hopper_reminder', ['minutes' => $minutes, 'lot' => $delivery->lot_code]));
        } catch (SmsDeliveryException) {
            $delivery->forceFill(['sms_status' => 'FAILED', 'sms_recipient_phone' => $phone])->save();

            throw OliveMillException::because('sms_failed', [], 502);
        }

        $delivery->forceFill(['sms_status' => 'SENT', 'sms_reminder_sent_at' => now(), 'sms_recipient_phone' => $phone])->save();

        return $delivery;
    }

    /**
     * Sends the reminder of every booked lot whose slot starts within the lead time and that was not reminded yet. Returns how many were sent.
     */
    public function sendDue(?Carbon $now = null): int
    {
        $now ??= now();
        $sent = 0;

        $due = OliveDelivery::query()
            ->whereNotNull('scheduled_at')
            ->whereNull('sms_reminder_sent_at')
            ->whereIn('status', [DeliveryStatus::Booked->value, DeliveryStatus::Weighed->value])
            ->whereBetween('scheduled_at', [$now, $now->copy()->addMinutes($this->leadMinutes())])
            ->get();

        foreach ($due as $delivery) {
            try {
                $this->send($delivery, max(1, (int) $now->diffInMinutes($delivery->scheduled_at)));
                $sent++;
            } catch (OliveMillException) {
                // A lot without a phone number or a refused message does not stop the others: the status of the lot tells what happened.
            }
        }

        return $sent;
    }

    public function leadMinutes(): int
    {
        return max(1, (int) config('olive_mill.sms_lead_minutes', 45));
    }

    private function phoneOf(OliveDelivery $delivery): ?string
    {
        $phone = $delivery->sms_recipient_phone ?? $delivery->farmer?->phone;

        return is_string($phone) && trim($phone) !== '' ? trim($phone) : null;
    }
}
