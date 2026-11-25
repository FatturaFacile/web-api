<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\ElectronicSignature\Enums\EnvelopeStatus;
use Modules\ElectronicSignature\Models\SignatureEnvelope;

/**
 * Reads of the signature center: the filtered list of envelopes and the KPI cards of the dashboard.
 */
final class SignatureQueryService
{
    /**
     * @param  array{status?: string|null, level?: string|null, jurisdiction?: string|null, provider?: string|null, signable_type?: string|null, signable_id?: string|null, search?: string|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<int, SignatureEnvelope>
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = SignatureEnvelope::query()->with('recipients')->latest('created_at');

        foreach (['status', 'jurisdiction', 'provider', 'signable_type', 'signable_id'] as $column) {
            if (($filters[$column] ?? null) !== null && $filters[$column] !== '') {
                $query->where($column, $filters[$column]);
            }
        }

        if (($filters['level'] ?? null) !== null && $filters['level'] !== '') {
            $query->where('required_level', $filters['level']);
        }

        if (($filters['search'] ?? null) !== null && trim((string) $filters['search']) !== '') {
            $term = '%'.mb_strtolower(trim((string) $filters['search'])).'%';
            $query->where(static function (Builder $builder) use ($term): void {
                $builder->whereRaw('LOWER(title) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(verification_code) LIKE ?', [$term])
                    ->orWhereHas('recipients', static fn (Builder $recipients) => $recipients->whereRaw('LOWER(email) LIKE ?', [$term])->orWhereRaw('LOWER(full_name) LIKE ?', [$term]));
            });
        }

        return $query->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
    }

    /**
     * @return array{pending: int, completed_this_month: int, expiring_soon: int, declined_or_failed: int}
     */
    public function dashboard(): array
    {
        $open = [EnvelopeStatus::Pending->value, EnvelopeStatus::PartiallySigned->value];

        return [
            'pending' => SignatureEnvelope::query()->whereIn('status', $open)->count(),
            'completed_this_month' => SignatureEnvelope::query()->where('status', EnvelopeStatus::Completed->value)->where('completed_at', '>=', now()->startOfMonth())->count(),
            'expiring_soon' => SignatureEnvelope::query()->whereIn('status', $open)->whereBetween('expires_at', [now(), now()->addDays(3)])->count(),
            'declined_or_failed' => SignatureEnvelope::query()->whereIn('status', [EnvelopeStatus::Declined->value, EnvelopeStatus::Expired->value])->where('updated_at', '>=', now()->subDays(30))->count(),
        ];
    }
}
