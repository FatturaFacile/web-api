<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use Illuminate\Validation\ValidationException;
use Modules\Communication\Contracts\DeliveryDriver;
use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Exceptions\DeliveryFailedException;
use Modules\Communication\Models\CommunicationChannel;
use Modules\Communication\Models\DeliveryLog;

/**
 * The one place where a message leaves through the driver of a channel and the attempt is logged, successful or not.
 * Without a configuration a channel uses the local driver, except e-mail, which uses the mailer of the installation.
 * The log keeps who sent what to whom and how it went; the body of the message is never stored.
 */
final readonly class MessageDispatcher
{
    /**
     * The settings of a channel, or null when the company never configured it.
     *
     * @throws ValidationException when the channel is switched off
     */
    public function settings(string $channel): ?CommunicationChannel
    {
        $settings = CommunicationChannel::where('channel', $channel)->first();

        if ($settings !== null && ! $settings->is_enabled) {
            throw ValidationException::withMessages(['channel' => __('sales.delivery_channel_disabled')]);
        }

        return $settings;
    }

    /**
     * Sends the message and logs the attempt.
     *
     * @param  array<string, mixed>  $log  columns of the delivery log known before sending (channel, recipient, sender, document...)
     */
    public function dispatch(string $channel, DeliveryMessage $message, array $log, ?CommunicationChannel $settings): DeliveryLog
    {
        $log = [...$log, 'channel' => $channel, 'driver' => (string) ($settings->driver ?? 'local'), 'recipient' => $message->recipient, 'subject' => mb_substr($message->subject, 0, 255)];

        try {
            $driver = $this->driver($channel, $settings);
        } catch (DeliveryFailedException $exception) {
            return DeliveryLog::create([...$log, 'status' => 'failed', 'error' => $exception->getMessage()]);
        }

        $log['driver'] = $driver->name();

        try {
            $result = $driver->send($message, $settings->credentials ?? []);
        } catch (DeliveryFailedException $exception) {
            return DeliveryLog::create([...$log, 'status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 1000)]);
        }

        return DeliveryLog::create([...$log, 'status' => $result['status'], 'provider_reference' => $result['reference']]);
    }

    private function driver(string $channel, ?CommunicationChannel $settings): DeliveryDriver
    {
        $drivers = DocumentSender::driversOf($channel);
        $name = $settings->driver ?? ($channel === 'email' ? 'mail' : 'local');

        if (! isset($drivers[$name])) {
            throw new DeliveryFailedException("The driver [{$name}] is not available for the {$channel} channel.");
        }

        return app($drivers[$name]);
    }
}
