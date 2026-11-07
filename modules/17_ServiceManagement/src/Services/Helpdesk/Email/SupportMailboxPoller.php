<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk\Email;

use Illuminate\Support\Facades\Cache;
use Modules\ServiceManagement\Models\SupportMailbox;
use Throwable;

/**
 * Reads the new messages of a support mailbox. The last UID read moves forward after each message, so a failure halfway
 * resumes where it stopped; a cache lock keeps two pollers off the same mailbox.
 */
final readonly class SupportMailboxPoller
{
    public function __construct(
        private SupportMailboxConnector $connector,
        private InboundEmailProcessor $processor,
    ) {}

    /**
     * @return int the number of messages read
     */
    public function poll(SupportMailbox $mailbox): int
    {
        $lock = Cache::lock("helpdesk-mailbox-poll:{$mailbox->id}", 600);

        if (! $lock->get()) {
            return 0;
        }

        $read = 0;

        try {
            $connection = $this->connector->open($mailbox);

            try {
                foreach ($connection->uidsAfter((int) $mailbox->last_uid) as $uid) {
                    $this->processor->process($mailbox, $uid, $connection->fetch($uid));
                    $mailbox->forceFill(['last_uid' => $uid])->save();
                    $read++;
                }
            } finally {
                $connection->close();
            }

            $mailbox->forceFill(['last_polled_at' => now(), 'last_error' => null])->save();
        } catch (Throwable $exception) {
            report($exception);
            $mailbox->forceFill(['last_polled_at' => now(), 'last_error' => mb_substr($exception->getMessage(), 0, 1000)])->save();
        } finally {
            $lock->release();
        }

        return $read;
    }
}
