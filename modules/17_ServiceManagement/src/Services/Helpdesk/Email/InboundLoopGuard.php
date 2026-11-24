<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk\Email;

use Modules\ServiceManagement\Models\SupportMailbox;
use Modules\ServiceManagement\Models\SupportMailboxMessage;

/**
 * Keeps automatic mail out of the helpdesk: out-of-office and other auto-replies, bounces, bulk and list mail, mail sent
 * by the helpdesk itself or by one of the tenant's own support mailboxes, and a sender writing faster than a person can
 * (two auto-responders answering each other).
 */
final class InboundLoopGuard
{
    /**
     * Header the helpdesk puts on every message it sends.
     */
    public const string LoopHeader = 'X-FF-Helpdesk';

    /**
     * @return array{outcome: string, reason: string}|null null when the message may enter the helpdesk
     */
    public function check(InboundEmail $email, SupportMailbox $mailbox): ?array
    {
        $autoSubmitted = strtolower(trim((string) $email->header('auto-submitted')));

        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return $this->autoReply("Auto-Submitted: {$autoSubmitted}");
        }

        if (in_array(strtolower(trim((string) $email->header('precedence'))), ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return $this->autoReply('Precedence: '.$email->header('precedence'));
        }

        foreach (['x-autoreply', 'x-autorespond', 'x-auto-response-suppress', 'list-id', 'list-unsubscribe'] as $header) {
            if ($email->header($header) !== null && ($header !== 'x-auto-response-suppress' || stripos((string) $email->header($header), 'all') !== false || stripos((string) $email->header($header), 'oof') !== false)) {
                return $this->autoReply("Header {$header}");
            }
        }

        if ($email->header(strtolower(self::LoopHeader)) !== null) {
            return $this->loop('Message sent by the helpdesk');
        }

        $from = (string) $email->fromEmail;

        if ($from === '' || preg_match('/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply)@/i', $from) === 1) {
            return $this->autoReply('System or no-reply sender');
        }

        $ownAddresses = SupportMailbox::query()->where('tenant_id', $mailbox->tenant_id)->pluck('email_address')->map(fn (string $address): string => strtolower($address))->all();

        if (in_array($from, $ownAddresses, true)) {
            return $this->loop('Sender is a support mailbox of the company');
        }

        if (preg_match('/^(auto(matic)?[ _-]?reply|out of (the )?office|abwesenheit|risposta automatica|absence|réponse automatique|undeliverable|delivery status notification)/iu', $email->subject) === 1) {
            return $this->autoReply('Automatic reply subject');
        }

        $recent = SupportMailboxMessage::query()
            ->where('tenant_id', $mailbox->tenant_id)
            ->where('from_email', $from)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recent >= (int) config('helpdesk.inbound_max_messages_per_sender_per_hour', 20)) {
            return $this->loop("More than {$recent} messages from {$from} in the last hour");
        }

        return null;
    }

    /**
     * @return array{outcome: string, reason: string}
     */
    private function autoReply(string $reason): array
    {
        return ['outcome' => SupportMailboxMessage::IgnoredAutoReply, 'reason' => $reason];
    }

    /**
     * @return array{outcome: string, reason: string}
     */
    private function loop(string $reason): array
    {
        return ['outcome' => SupportMailboxMessage::IgnoredLoop, 'reason' => $reason];
    }
}
