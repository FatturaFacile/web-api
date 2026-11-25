<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DebtCollection\Models\Policy;

/**
 * @mixin Policy
 */
final class PolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'jurisdiction' => $this->jurisdiction,
            'currency' => $this->currency,
            'customer_id' => $this->customer_id,
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
            'min_overdue_amount' => (string) $this->min_overdue_amount,
            'interest_basis' => $this->interest_basis,
            'contractual_rate_percent' => $this->contractual_rate_percent === null ? null : (string) $this->contractual_rate_percent,
            'levels' => $this->whenLoaded('levels', fn (): array => $this->levels->map(static fn ($level): array => [
                'id' => $level->id,
                'level' => (int) $level->level,
                'min_days_overdue' => (int) $level->min_days_overdue,
                'wait_days' => (int) $level->wait_days,
                'deadline_days' => (int) $level->deadline_days,
                'fee_amount' => (string) $level->fee_amount,
                'charge_interest' => (bool) $level->charge_interest,
                'channels' => $level->channels ?? [],
                'legal_warning' => (bool) $level->legal_warning,
                'is_active' => (bool) $level->is_active,
            ])->all()),
        ];
    }
}
