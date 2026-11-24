<?php

declare(strict_types=1);

namespace Modules\Communication\Drivers;

use Modules\Communication\Contracts\DeliveryDriver;
use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Exceptions\DeliveryFailedException;
use Modules\Communication\Services\SmtpTransportFactory;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends through the mail server the company configured (host, port, encryption, login), not the one of the
 * installation. The server settings and the password live encrypted in the channel configuration.
 */
final class SmtpDriver implements DeliveryDriver
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly SmtpTransportFactory $transports) {}

    /**
     * Driver or resolver name.
     */
    public function name(): string
    {
        return 'smtp';
    }

    /**
     * Send the message.
     */
    public function send(DeliveryMessage $message, array $credentials): array
    {
        $from = (string) ($credentials['from_address'] ?? '');

        if ($from === '') {
            throw new DeliveryFailedException('The sender address of the SMTP server is not configured.');
        }

        try {
            $email = (new Email)
                ->from(new Address($from, (string) ($credentials['from_name'] ?? '')))
                ->to($message->recipient)
                ->subject($message->subject)
                ->text($message->body);

            if ($message->attachmentContent !== null) {
                $email->attach($message->attachmentContent, (string) $message->attachmentName, 'application/pdf');
            }

            $sent = $this->transports->make($credentials)->send($email);
        } catch (DeliveryFailedException $exception) {
            throw $exception;
        } catch (TransportExceptionInterface $exception) {
            throw new DeliveryFailedException('The mail server refused the message or cannot be reached: '.mb_substr($exception->getMessage(), 0, 300), previous: $exception);
        } catch (Throwable $exception) {
            throw new DeliveryFailedException('The message could not be built: '.mb_substr($exception->getMessage(), 0, 300), previous: $exception);
        }

        return ['status' => 'sent', 'reference' => $sent?->getMessageId()];
    }
}
