<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Contracts\Events\ContractObligationDueEvent;
use Modules\Contracts\Models\ContractObligation;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Obligations and milestones of contracts: listing by deadline, closing them, and the daily reminder. An item is
 * reminded once, when its due date comes within its reminder window or has already passed.
 */
final readonly class ContractObligationService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private EventBusInterface $events) {}

    /**
     * Obligations of the active company, soonest due first.
     *
     * @param  array{contract_kind?: ?string, contract_id?: ?string, type?: ?string, status?: ?string, owner_user_id?: ?string, due_within_days?: int|string|null, overdue?: bool}  $filters
     * @return Builder<ContractObligation>
     */
    public function query(array $filters, CarbonImmutable $today): Builder
    {
        return ContractObligation::query()
            ->when($filters['contract_kind'] ?? null, static fn (Builder $query, string $kind) => $query->where('contract_kind', $kind))
            ->when($filters['contract_id'] ?? null, static fn (Builder $query, string $id) => $query->where('contract_id', $id))
            ->when($filters['type'] ?? null, static fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, static fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['owner_user_id'] ?? null, static fn (Builder $query, string $owner) => $query->where('owner_user_id', $owner))
            ->when($filters['due_within_days'] ?? null, static fn (Builder $query, int|string $days) => $query->where('status', 'open')->whereBetween('due_date', [$today->toDateString(), $today->addDays((int) $days)->toDateString()]))
            ->when($filters['overdue'] ?? false, static fn (Builder $query) => $query->where('status', 'open')->where('due_date', '<', $today->toDateString()))
            ->orderBy('due_date')
            ->orderBy('created_at');
    }

    /**
     * Closes an open item as done or waived.
     *
     * @throws ConflictHttpException when the item is not open
     */
    public function close(ContractObligation $obligation, string $outcome, ?string $note, ?string $userId): ContractObligation
    {
        return DB::transaction(function () use ($obligation, $outcome, $note, $userId): ContractObligation {
            $locked = ContractObligation::query()->lockForUpdate()->findOrFail($obligation->id);

            if ($locked->status !== 'open') {
                throw new ConflictHttpException("This item is already {$locked->status}.");
            }

            $locked->update(['status' => $outcome, 'completed_at' => now(), 'completed_by' => $userId, 'completion_note' => $note]);

            return $locked;
        });
    }

    /**
     * Raises the reminder of every open item of a tenant whose window has begun. Returns how many were raised.
     */
    public function remindDue(string $tenantId, CarbonImmutable $today): int
    {
        $reminded = 0;

        ContractObligation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 'open')
            ->whereNull('reminded_at')
            ->orderBy('due_date')
            ->each(function (ContractObligation $item) use ($today, &$reminded): void {
                // Portable replacement of the SQL date arithmetic: the window opens remind_days_before days before the due date.
                if ($item->due_date->subDays((int) $item->remind_days_before)->toDateString() > $today->toDateString()) {
                    return;
                }

                $item->forceFill(['reminded_at' => now()])->saveQuietly();

                $this->events->publishAndDispatch(new ContractObligationDueEvent(
                    obligationId: (string) $item->id,
                    contractKind: (string) $item->contract_kind,
                    contractId: (string) $item->contract_id,
                    type: (string) $item->type,
                    title: (string) $item->title,
                    dueDate: $item->due_date->toDateString(),
                    isOverdue: $item->isOverdue($today),
                    ownerUserId: $item->owner_user_id,
                    tenantId: (string) $item->tenant_id,
                ));
                $reminded++;
            });

        return $reminded;
    }
}
