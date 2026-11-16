<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use App\Models\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\CRM\Models\AutomationEnrollment;
use Modules\CRM\Models\AutomationSequence;
use Modules\CRM\Models\AutomationStep;
use Modules\CRM\Models\AutomationStepLog;
use Modules\CRM\Models\Consent;
use Modules\CRM\Models\Lead;
use Modules\CRM\Notifications\CampaignMessageNotification;
use Throwable;

/**
 * Marketing automation: enrols contacts or leads in a sequence and runs its steps when they are due. Before every step
 * the exit conditions of the sequence are checked; an email goes out only with a current marketing opt-in
 * (ConsentService), otherwise the step is logged as skipped. Every executed step is written to the step log. Mail is
 * handed to the notification queue outside any transaction.
 */
final class AutomationService
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly SegmentService $segments,
    ) {}

    /**
     * Enrols a subject. Fails when the sequence is inactive or empty or the subject is already active in it.
     */
    public function enroll(AutomationSequence $sequence, ?string $contactId, ?string $leadId): AutomationEnrollment
    {
        $this->assertCanEnroll($sequence, $contactId, $leadId);

        $subject = $contactId !== null ? ['contact_id' => $contactId] : ['lead_id' => $leadId];
        $alreadyActive = AutomationEnrollment::query()
            ->where('tenant_id', $sequence->tenant_id)
            ->where('sequence_id', $sequence->id)
            ->where('status', 'active')
            ->where($subject)
            ->exists();

        if ($alreadyActive) {
            throw ValidationException::withMessages(['subject' => 'The subject is already enrolled in this sequence.']);
        }

        $first = $this->orderedSteps($sequence)->first();

        return AutomationEnrollment::create([
            'tenant_id' => $sequence->tenant_id,
            'sequence_id' => $sequence->id,
            ...$subject,
            'status' => 'active',
            'current_position' => $first->position,
            'next_run_at' => now()->addDays($first->delay_days),
            'enrolled_at' => now(),
        ]);
    }

    /**
     * Enrols a subject in every active sequence with this trigger and entity, silently skipping the ones that do not
     * apply (segment mismatch, already enrolled, empty sequence). Returns how many enrolments were created.
     */
    public function enrollFromTrigger(string $tenantId, string $trigger, ?string $contactId, ?string $leadId): int
    {
        $entity = $contactId !== null ? 'contacts' : 'leads';
        $count = 0;

        AutomationSequence::query()
            ->where('tenant_id', $tenantId)
            ->where('trigger_type', $trigger)
            ->where('entity', $entity)
            ->where('is_active', true)
            ->get()
            ->each(function (AutomationSequence $sequence) use ($contactId, $leadId, &$count): void {
                try {
                    $this->enroll($sequence, $contactId, $leadId);
                    $count++;
                } catch (ValidationException) {
                    // Not applicable: the subject stays out of this sequence.
                }
            });

        return $count;
    }

    /**
     * Leaves a sequence for a reason.
     */
    public function exit(AutomationEnrollment $enrollment, string $reason): AutomationEnrollment
    {
        if ($enrollment->status !== 'active') {
            throw ValidationException::withMessages(['status' => 'The enrollment is already '.$enrollment->status.'.']);
        }

        $enrollment->forceFill(['status' => 'exited', 'exit_reason' => $reason, 'next_run_at' => null, 'finished_at' => now()])->save();

        return $enrollment;
    }

    /**
     * Runs the due steps of the active enrolments (one step per enrolment per run). Returns the number of enrolments handled.
     */
    public function processDue(?string $tenantId = null): int
    {
        $handled = 0;

        AutomationEnrollment::query()
            ->when($tenantId !== null, static fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('status', 'active')
            ->where('next_run_at', '<=', now())
            ->with('sequence')
            ->orderBy('next_run_at')
            ->each(function (AutomationEnrollment $enrollment) use (&$handled): void {
                $this->processEnrollment($enrollment);
                $handled++;
            });

        return $handled;
    }

    public function processEnrollment(AutomationEnrollment $enrollment): void
    {
        $sequence = $enrollment->sequence;
        $reason = $this->exitReason($sequence, $enrollment);

        if ($reason !== null) {
            $this->exit($enrollment, $reason);

            return;
        }

        $step = $this->orderedSteps($sequence)->firstWhere('position', $enrollment->current_position);

        if ($step === null) {
            $this->finish($enrollment);

            return;
        }

        [$status, $message, $notification] = $this->execute($enrollment, $step);

        if ($notification !== null) {
            try {
                Notification::route('mail', $notification['email'])->notify(new CampaignMessageNotification((string) $sequence->id, $notification['subject'], $notification['body']));
            } catch (Throwable $exception) {
                $status = 'failed';
                $message = $exception->getMessage();
            }
        }

        DB::transaction(function () use ($enrollment, $step, $status, $message, $sequence): void {
            AutomationStepLog::create([
                'tenant_id' => $enrollment->tenant_id,
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
                'position' => $step->position,
                'step_type' => $step->step_type,
                'status' => $status,
                'message' => $message,
                'executed_at' => now(),
            ]);

            $next = $this->orderedSteps($sequence)->first(static fn (AutomationStep $candidate): bool => $candidate->position > $step->position);

            if ($next === null) {
                $this->finish($enrollment);

                return;
            }

            $enrollment->forceFill(['current_position' => $next->position, 'next_run_at' => now()->addDays($next->delay_days)])->save();
        });
    }

    /**
     * @return array{0: string, 1: string|null, 2: array{email: string, subject: string, body: string}|null}
     */
    private function execute(AutomationEnrollment $enrollment, AutomationStep $step): array
    {
        if ($step->step_type !== 'email') {
            return [$step->step_type === 'task' ? 'executed' : 'waited', $step->step_type === 'task' ? ($step->subject ?? 'Task for the owner') : null, null];
        }

        if (! $this->consents->allows($enrollment->contact_id, $enrollment->lead_id, 'email', 'marketing')) {
            return ['skipped', 'No marketing email consent', null];
        }

        $email = $this->consents->emailOf(
            $enrollment->contact_id !== null ? Contact::query()->find($enrollment->contact_id) : null,
            $enrollment->lead_id !== null ? Lead::query()->find($enrollment->lead_id) : null,
        );

        if ($email === null) {
            return ['skipped', 'The subject has no email address', null];
        }

        return ['sent', $email, ['email' => $email, 'subject' => (string) $step->subject, 'body' => (string) $step->body]];
    }

    private function exitReason(AutomationSequence $sequence, AutomationEnrollment $enrollment): ?string
    {
        $conditions = (array) ($sequence->exit_conditions ?? []);

        if ($enrollment->lead_id !== null) {
            $status = Lead::query()->whereKey($enrollment->lead_id)->value('status');

            if ($status === 'converted' && in_array('lead_converted', $conditions, true)) {
                return 'lead_converted';
            }

            if ($status === 'disqualified' && in_array('lead_disqualified', $conditions, true)) {
                return 'lead_disqualified';
            }
        }

        if (in_array('consent_withdrawn', $conditions, true)
            && $this->consents->effectiveStatus($enrollment->contact_id, $enrollment->lead_id, 'email', 'marketing') === Consent::Withdrawn) {
            return 'consent_withdrawn';
        }

        return null;
    }

    private function finish(AutomationEnrollment $enrollment): void
    {
        $enrollment->forceFill(['status' => 'completed', 'next_run_at' => null, 'finished_at' => now()])->save();
    }

    /**
     * @return Collection<int, AutomationStep>
     */
    private function orderedSteps(AutomationSequence $sequence): Collection
    {
        return AutomationStep::query()->where('tenant_id', $sequence->tenant_id)->where('sequence_id', $sequence->id)->orderBy('position')->get();
    }

    private function assertCanEnroll(AutomationSequence $sequence, ?string $contactId, ?string $leadId): void
    {
        if (($contactId === null) === ($leadId === null)) {
            throw ValidationException::withMessages(['subject' => 'Enrol exactly one contact or one lead.']);
        }

        if (! $sequence->is_active) {
            throw ValidationException::withMessages(['sequence' => 'The sequence is not active.']);
        }

        if (($sequence->entity === 'leads') !== ($leadId !== null)) {
            throw ValidationException::withMessages(['subject' => 'This sequence enrols '.$sequence->entity.'.']);
        }

        if ($this->orderedSteps($sequence)->isEmpty()) {
            throw ValidationException::withMessages(['sequence' => 'The sequence has no steps.']);
        }

        if ($sequence->segment_id !== null) {
            $segment = $sequence->loadMissing('segment')->segment;
            $inSegment = $segment !== null && $this->segments->query($segment)->whereKey($leadId ?? $contactId)->exists();

            if (! $inSegment) {
                throw ValidationException::withMessages(['subject' => 'The subject is not in the segment of the sequence.']);
            }
        }
    }
}
