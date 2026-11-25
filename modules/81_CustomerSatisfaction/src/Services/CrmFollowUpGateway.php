<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;
use Throwable;

/**
 * The thin edge towards the CRM (Mod 024): finds the account behind a customer, creates the mandatory follow-up task and moves the
 * churn risk. Every call degrades to "nothing done" when the CRM module or its tables are absent, so a detractor is never lost
 * because of an optional module.
 */
final class CrmFollowUpGateway
{
    /** @var list<string> */
    public const array ChurnScale = ['low', 'medium', 'high', 'critical'];

    public function available(): bool
    {
        return class_exists(Account::class) && Schema::hasTable('crm_accounts');
    }

    public function accountOf(string $customerId): ?Account
    {
        if (! $this->available()) {
            return null;
        }

        return Account::query()->where('contact_id', $customerId)->first();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createFollowUpTask(?Account $account, string $customerId, string $subject, string $priority, Carbon $dueAt, ?string $assigneeId, array $metadata): ?string
    {
        if (! class_exists(Activity::class) || ! Schema::hasTable('crm_activities')) {
            return null;
        }

        try {
            $activity = Activity::query()->create([
                'subject' => mb_substr($subject, 0, 200),
                'type' => 'task',
                'status' => 'pending',
                'priority' => $priority === 'urgent' ? 'urgent' : 'high',
                'due_date' => $dueAt,
                'assigned_user_id' => $assigneeId,
                'related_type' => $account !== null ? 'account' : 'contact',
                'related_id' => $account->id ?? $customerId,
                'metadata' => $metadata,
            ]);

            return (string) $activity->id;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function completeTask(?string $taskId): void
    {
        if ($taskId === null || ! class_exists(Activity::class) || ! Schema::hasTable('crm_activities')) {
            return;
        }

        Activity::query()->whereKey($taskId)->where('status', '!=', 'completed')->update(['status' => 'completed', 'completed_at' => now()]);
    }

    /**
     * Raises (never lowers) the churn risk of the account: a detractor is at least "high", an urgent one "critical".
     *
     * @return array{previous: string|null, new: string|null, changed: bool}
     */
    public function raiseChurnRisk(?Account $account, string $minimum): array
    {
        if ($account === null) {
            return ['previous' => null, 'new' => null, 'changed' => false];
        }

        $previous = (string) ($account->churn_risk ?: 'low');
        $target = array_search($minimum, self::ChurnScale, true);
        $current = array_search($previous, self::ChurnScale, true);
        $new = ($target !== false && ($current === false || $target > $current)) ? $minimum : $previous;

        if ($new !== $previous) {
            $account->forceFill(['churn_risk' => $new])->save();
        }

        return ['previous' => $previous, 'new' => $new, 'changed' => $new !== $previous];
    }
}
