<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Enums\ContractStatus;
use Modules\Contracts\Models\ContractStatusChange;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Moves a contract along its lifecycle (draft, review, active, renewal, expired, terminated) and keeps the history. The
 * status lives where it always did, on the service contract or the supplier contract, so every module that reads it keeps
 * working. A contract held by an approval workflow is not touched from here: the workflow owns it until it ends.
 */
final readonly class ContractLifecycle
{
    /**
     * Move the contract to a new status.
     *
     * @throws ConflictHttpException when the move is not allowed from the current status, the contract is locked by a
     *                               workflow, or activating it would start a contract that has already ended
     */
    public function transition(ContractKind $kind, Model $contract, ContractStatus $to, ?string $reason, ?string $userId, ?CarbonImmutable $today = null): Model
    {
        return DB::transaction(function () use ($kind, $contract, $to, $reason, $userId, $today): Model {
            $locked = $kind->modelClass()::withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->getKey());

            if ($locked->getAttribute('is_locked')) {
                throw new ConflictHttpException('This contract is locked by an approval workflow: its status changes when the workflow ends.');
            }

            $from = ContractStatus::fromStored((string) $locked->getAttribute('status'));

            if ($from === null) {
                throw new ConflictHttpException("The status [{$locked->getAttribute('status')}] is outside the contract lifecycle and cannot be changed from here.");
            }

            if (! in_array($to, $from->next(), true)) {
                throw new ConflictHttpException("A contract cannot go from {$from->value} to {$to->value}.");
            }

            $end = $locked->getAttribute($kind->endColumn());

            if ($to === ContractStatus::Active && $end !== null && CarbonImmutable::parse($end)->lt($today ?? CarbonImmutable::today())) {
                throw new ConflictHttpException('The end date of this contract has passed: renew it with a new end date instead of activating it.');
            }

            $locked->update(['status' => $to->value]);

            ContractStatusChange::create([
                'tenant_id' => $locked->getAttribute('tenant_id'),
                'contract_kind' => $kind->value,
                'contract_id' => $locked->getKey(),
                'from_status' => $from->value,
                'to_status' => $to->value,
                'reason' => $reason,
                'changed_by' => $userId,
            ]);

            return $locked;
        });
    }
}
