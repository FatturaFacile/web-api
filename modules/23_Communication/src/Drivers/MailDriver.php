<?php

declare(strict_types=1);

namespace Modules\Communication\Drivers;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Modules\Communication\Contracts\DeliveryDriver;
use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Exceptions\DeliveryFailedException;
use Throwable;

/**
 * Sends through the mailer of the installation, with the PDF of the document attached.
 */
final class MailDriver implements DeliveryDriver
{
    public function name(): string
    {
        return 'mail';
    }

    public function send(DeliveryMessage $delivery, array $credentials): array
    {
        try {
            Mail::raw($delivery->body, static function (Message $mail) use ($delivery): void {
                $mail->to($delivery->recipient)->subject($delivery->subject);

                if ($delivery->attachmentContent !== null) {
                    $mail->attachData($delivery->attachmentContent, (string) $delivery->attachmentName, ['mime' => 'application/pdf']);
                }
            });
        } catch (Throwable $e) {
            throw new DeliveryFailedException($e->getMessage(), previous: $e);
        }

        return ['status' => 'sent', 'reference' => null];
    }
}
