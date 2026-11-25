<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use App\Models\Contact;
use Illuminate\Support\Carbon;
use Modules\CustomerSatisfaction\Contracts\SurveyMessageGatewayInterface;
use Modules\CustomerSatisfaction\Domain\Enums\DispatchStatus;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyChannel;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;
use Throwable;

/**
 * Sends the scheduled invitations that are due (php artisan surveys:process-dispatches), one tenant at a time. A refused message
 * marks the dispatch as failed with the reason, so the monitor shows it and nothing is sent twice.
 */
final class SurveyDispatchProcessor
{
    public function __construct(
        private readonly SurveyMessageGatewayInterface $gateway,
        private readonly SurveyMessageComposer $composer,
    ) {}

    /**
     * @return array{sent: int, failed: int, suppressed: int}
     */
    public function processDue(?Carbon $now = null, ?int $limit = null): array
    {
        $now ??= now();
        $result = ['sent' => 0, 'failed' => 0, 'suppressed' => 0];

        $due = SurveyDispatch::query()
            ->with('template')
            ->where('status', DispatchStatus::Scheduled->value)
            ->where('scheduled_for', '<=', $now)
            ->orderBy('scheduled_for')
            ->limit($limit ?? (int) config('customer_satisfaction.batch_size'))
            ->get();

        foreach ($due as $dispatch) {
            $template = $dispatch->template;
            $contact = Contact::query()->find($dispatch->customer_id);
            $recipient = $dispatch->channel === SurveyChannel::Email ? $contact?->email : $contact?->phone;

            if ($template === null || ! is_string($recipient) || trim($recipient) === '') {
                $dispatch->update(['status' => DispatchStatus::Suppressed->value, 'throttle_reason' => 'missing_contact']);
                $result['suppressed']++;

                continue;
            }

            try {
                $message = $this->composer->compose($dispatch, $template, $contact);
                $this->gateway->send($dispatch->channel, $recipient, $message['subject'], $message['body']);
                $dispatch->update(['status' => DispatchStatus::Sent->value, 'sent_at' => $now]);
                $result['sent']++;
            } catch (Throwable $e) {
                report($e);
                $dispatch->update([
                    'status' => DispatchStatus::Failed->value,
                    'metadata' => [...($dispatch->metadata ?? []), 'error' => mb_substr($e->getMessage(), 0, 300)],
                ]);
                $result['failed']++;
            }
        }

        return $result;
    }
}
