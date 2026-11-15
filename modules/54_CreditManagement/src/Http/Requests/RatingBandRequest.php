<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\CreditManagement\Models\CreditRatingBand;

/**
 * Payload of a rating band. The score ranges of the bands of a company never overlap.
 */
final class RatingBandRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'code' => ['required', 'string', 'max:10'],
            'name' => ['required', 'string', 'max:120'],
            'min_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_score' => ['required', 'numeric', 'min:0', 'max:100', 'gte:min_score'],
            'risk_level' => ['required', Rule::in(CreditRatingBand::RiskLevels)],
            'limit_multiplier' => ['required', 'numeric', 'min:0', 'max:100'],
            'limit_ceiling' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ]);
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $current = $this->route('id') !== null ? CreditRatingBand::query()->find($this->route('id')) : null;
            $min = (float) ($this->input('min_score') ?? $current?->min_score);
            $max = (float) ($this->input('max_score') ?? $current?->max_score);
            $code = (string) ($this->input('code') ?? $current?->code);
            $others = CreditRatingBand::query()->where('tenant_id', (string) app(TenantContext::class)->getTenantId())->when($current !== null, static fn ($query) => $query->whereKeyNot($current->id))->get();

            if ($others->contains(static fn (CreditRatingBand $band): bool => (float) $band->min_score <= $max && (float) $band->max_score >= $min)) {
                $validator->errors()->add('min_score', __('credit_errors.band.overlap'));
            }

            if ($others->contains(static fn (CreditRatingBand $band): bool => $band->code === $code)) {
                $validator->errors()->add('code', __('credit_errors.band.code_taken'));
            }
        }];
    }
}
