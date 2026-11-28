<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Modules\AdventureParks\Exceptions\AdventureParksException;
use Modules\AdventureParks\Models\EducationalWorkshop;
use Modules\AdventureParks\Models\SchoolBookingWorkshop;

/**
 * Catalogue of the educational farm workshops of a company: the standard (global) workshops with their names in every language,
 * where a company's own row of the same code replaces the global one (price, duration, capacity).
 */
final class WorkshopCatalogue
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return Collection<int, EducationalWorkshop>
     */
    public function all(): Collection
    {
        $rows = EducationalWorkshop::query()->orderBy('code')->get();
        $own = $rows->filter(fn (EducationalWorkshop $row): bool => $row->tenant_id !== null)->keyBy('code');

        return $rows
            ->filter(fn (EducationalWorkshop $row): bool => $row->tenant_id !== null || ! $own->has($row->code))
            ->sortBy('code')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): EducationalWorkshop
    {
        $code = strtoupper((string) $data['code']);

        if (EducationalWorkshop::query()->where('code', $code)->whereNotNull('tenant_id')->exists()) {
            throw AdventureParksException::refuse('workshop_code_taken', 'A workshop with this code already exists.', ['code' => $code]);
        }

        $workshop = new EducationalWorkshop([...$data, 'code' => $code, 'tenant_id' => (string) $this->tenants->getTenantId(), 'created_by' => $userId]);
        $workshop->save();

        return $workshop;
    }

    /**
     * Updates a workshop of the company. Changing a standard (global) workshop creates the company's own copy with the same
     * code and the standard texts, so the standard catalogue stays untouched for the other companies.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(EducationalWorkshop $workshop, array $data, ?string $userId): EducationalWorkshop
    {
        if ($workshop->tenant_id !== null) {
            $workshop->fill($data);
            $workshop->updated_by = $userId;
            $workshop->save();

            return $workshop->refresh();
        }

        $copy = $this->all()->first(fn (EducationalWorkshop $row): bool => $row->code === $workshop->code && $row->tenant_id !== null);

        if ($copy !== null) {
            return $this->update($copy, $data, $userId);
        }

        $own = new EducationalWorkshop([
            'tenant_id' => (string) $this->tenants->getTenantId(),
            'code' => $workshop->code,
            'duration_minutes' => $workshop->duration_minutes,
            'max_participants' => $workshop->max_participants,
            'price_per_student' => $workshop->price_per_student,
            'name_translations' => $workshop->name_translations,
            'description_translations' => $workshop->description_translations,
            'is_active' => $workshop->is_active,
            ...$data,
            'created_by' => $userId,
        ]);
        $own->save();

        return $own;
    }

    public function delete(EducationalWorkshop $workshop): void
    {
        if ($workshop->tenant_id === null) {
            throw AdventureParksException::refuse('workshop_global', 'A standard workshop cannot be deleted: deactivate it with your own copy.', ['code' => $workshop->code]);
        }

        if (SchoolBookingWorkshop::query()->where('workshop_id', $workshop->id)->exists()) {
            throw AdventureParksException::refuse('workshop_in_use', 'The workshop is used by a school booking.', ['code' => $workshop->code]);
        }

        $workshop->delete();
    }
}
