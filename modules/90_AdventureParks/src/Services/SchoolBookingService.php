<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\AdventureParks\Contracts\SchoolInvoicePort;
use Modules\AdventureParks\Domain\Enums\SchoolBookingStatus;
use Modules\AdventureParks\Domain\Services\SchoolQuoteCalculator;
use Modules\AdventureParks\Exceptions\AdventureParksException;
use Modules\AdventureParks\Models\EducationalWorkshop;
use Modules\AdventureParks\Models\SchoolBooking;
use Modules\AdventureParks\Models\SchoolBookingWorkshop;

/**
 * School trips of the educational farm: quote (free teachers, picnic seats, total), booking with the picnic capacity of the day,
 * life cycle and the electronic invoice to the public administration through the invoicing port.
 */
final class SchoolBookingService
{
    public function __construct(
        private readonly ParkSettings $settings,
        private readonly WorkshopCatalogue $catalogue,
        private readonly SchoolInvoicePort $invoices,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  list<string>  $workshopIds
     * @return array{free_teachers: int, charged_teachers: int, picnic_seats: int, total_amount: string}
     */
    public function quote(int $payingStudents, int $disabledStudents, int $accompanyingTeachers, array $workshopIds): array
    {
        $workshops = $this->resolveWorkshops($workshopIds);

        return $this->calculator()->quote(
            $payingStudents,
            $disabledStudents,
            $accompanyingTeachers,
            $workshops->map(fn (EducationalWorkshop $workshop): string => (string) $workshop->price_per_student)->values()->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $data  validated payload (workshop_ids included)
     */
    public function create(array $data, ?string $userId): SchoolBooking
    {
        $id = DB::transaction(function () use ($data, $userId): string {
            $workshops = $this->resolveWorkshops(array_values((array) $data['workshop_ids']));
            $booking = new SchoolBooking($this->attributes($data) + [
                'disabled_students' => 0,
                'accompanying_teachers' => 0,
                'picnic_reserved' => false,
                'split_payment' => true,
                'tenant_id' => (string) $this->tenants->getTenantId(),
                'status' => SchoolBookingStatus::Draft->value,
                'vat_rate' => (string) config('adventure_parks.school_vat_rate', '10'),
                'created_by' => $userId,
            ]);

            $this->price($booking, $workshops);
            $this->assertPicnicCapacity($booking);
            $booking->save();
            $this->syncWorkshops($booking, $workshops, $userId);

            return $booking->id;
        });

        return $this->fresh($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data, ?string $userId): SchoolBooking
    {
        DB::transaction(function () use ($id, $data, $userId): void {
            /** @var SchoolBooking $booking */
            $booking = SchoolBooking::query()->lockForUpdate()->findOrFail($id);
            $booking->assertEditable();
            $booking->fill($this->attributes($data));
            $booking->updated_by = $userId;

            $workshops = isset($data['workshop_ids'])
                ? $this->resolveWorkshops(array_values((array) $data['workshop_ids']))
                : $this->workshopsOf($booking);

            $this->price($booking, $workshops);
            $this->assertPicnicCapacity($booking);
            $booking->save();

            if (isset($data['workshop_ids'])) {
                SchoolBookingWorkshop::query()->where('school_booking_id', $booking->id)->delete();
            }

            $this->syncWorkshops($booking, $workshops, $userId);
        });

        return $this->fresh($id);
    }

    public function delete(string $id): void
    {
        /** @var SchoolBooking $booking */
        $booking = SchoolBooking::query()->findOrFail($id);

        if ($booking->statusEnum() !== SchoolBookingStatus::Draft) {
            throw AdventureParksException::refuse('booking_locked', 'Only a draft booking can be deleted.', ['status' => $booking->status]);
        }

        DB::transaction(function () use ($booking): void {
            SchoolBookingWorkshop::query()->where('school_booking_id', $booking->id)->delete();
            $booking->delete();
        });
    }

    public function confirm(string $id): SchoolBooking
    {
        /** @var SchoolBooking $booking */
        $booking = SchoolBooking::query()->findOrFail($id);
        $booking->confirm();
        $booking->save();

        return $this->fresh($id);
    }

    public function complete(string $id): SchoolBooking
    {
        /** @var SchoolBooking $booking */
        $booking = SchoolBooking::query()->findOrFail($id);
        $booking->complete();
        $booking->save();

        return $this->fresh($id);
    }

    /**
     * Creates the invoice of the trip in the invoicing module (split payment, CIG / CUP, IPA office code) and marks the booking
     * INVOICED. The free teachers appear as a zero-price line so the school sees them on the document.
     */
    public function generateInvoice(string $id, ?string $userId): SchoolBooking
    {
        DB::transaction(function () use ($id, $userId): void {
            /** @var SchoolBooking $booking */
            $booking = SchoolBooking::query()->lockForUpdate()->findOrFail($id);
            $booking->assertInvoiceable();

            $workshops = SchoolBookingWorkshop::query()->where('school_booking_id', $booking->id)->with('workshop')->get();
            $heads = $booking->paying_students + $booking->charged_teachers;
            $rate = (string) $booking->vat_rate;
            $locale = app()->getLocale();
            $lines = [];

            foreach ($workshops as $line) {
                $name = $line->workshop?->localizedName($locale) ?? 'Workshop';
                $lines[] = ['description' => $name.' - '.$booking->institute_name, 'quantity' => $heads, 'unit_price' => (string) $line->unit_price, 'tax_rate' => $rate];
            }

            if ($booking->free_teachers > 0) {
                $lines[] = ['description' => (string) __('adventure_parks.invoice.free_teachers'), 'quantity' => $booking->free_teachers, 'unit_price' => '0.00', 'tax_rate' => $rate];
            }

            $wording = (string) __('adventure_parks.invoice.wording', [
                'institute' => $booking->institute_name,
                'date' => $booking->visit_date->toDateString(),
                'students' => $booking->paying_students,
            ]);

            $reference = $this->invoices->createDraft($booking, $lines, $wording, $userId);
            $booking->markInvoiced($reference->invoiceId, $reference->invoiceNumber, now());
            $booking->save();
        });

        return $this->fresh($id);
    }

    /**
     * @return Collection<int, SchoolBooking>
     */
    public function list(?string $status): Collection
    {
        return SchoolBooking::query()
            ->with('workshopLines.workshop')
            ->when($status, fn ($query, string $value) => $query->where('status', $value))
            ->orderByDesc('visit_date')
            ->orderBy('institute_name')
            ->get();
    }

    public function fresh(string $id): SchoolBooking
    {
        return SchoolBooking::query()->with('workshopLines.workshop')->findOrFail($id);
    }

    private function calculator(): SchoolQuoteCalculator
    {
        return new SchoolQuoteCalculator($this->settings->teacherFreeRatio(), $this->settings->teacherFreePerDisabled());
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<int, EducationalWorkshop>
     */
    private function resolveWorkshops(array $ids): Collection
    {
        $available = $this->catalogue->all()->where('is_active', true)->keyBy('id');
        $selected = new Collection;

        foreach (array_unique($ids) as $id) {
            $workshop = $available->get($id);

            if ($workshop === null) {
                throw AdventureParksException::refuse('workshop_not_found', 'The workshop is not available.', ['workshop_id' => $id]);
            }

            $selected->push($workshop);
        }

        return $selected;
    }

    /**
     * @return Collection<int, EducationalWorkshop>
     */
    private function workshopsOf(SchoolBooking $booking): Collection
    {
        return SchoolBookingWorkshop::query()->where('school_booking_id', $booking->id)->with('workshop')->get()
            ->map(function (SchoolBookingWorkshop $line): EducationalWorkshop {
                /** @var EducationalWorkshop $workshop */
                $workshop = $line->workshop;
                $workshop->price_per_student = $line->unit_price;

                return $workshop;
            })->values();
    }

    /**
     * @param  Collection<int, EducationalWorkshop>  $workshops
     */
    private function price(SchoolBooking $booking, Collection $workshops): void
    {
        $booking->applyQuote($this->calculator()->quote(
            (int) $booking->paying_students,
            (int) $booking->disabled_students,
            (int) $booking->accompanying_teachers,
            $workshops->map(fn (EducationalWorkshop $workshop): string => (string) $workshop->price_per_student)->values()->all(),
        ));
    }

    /**
     * @param  Collection<int, EducationalWorkshop>  $workshops
     */
    private function syncWorkshops(SchoolBooking $booking, Collection $workshops, ?string $userId): void
    {
        foreach ($workshops as $workshop) {
            (new SchoolBookingWorkshop([
                'tenant_id' => $booking->tenant_id,
                'school_booking_id' => $booking->id,
                'workshop_id' => $workshop->id,
                'quantity_students' => $booking->paying_students,
                'unit_price' => $workshop->price_per_student,
                'created_by' => $userId,
            ]))->save();
        }
    }

    /**
     * The picnic area has a fixed number of seats per day: the seats of every other booking of the day plus the seats of this one
     * must fit.
     */
    private function assertPicnicCapacity(SchoolBooking $booking): void
    {
        if (! $booking->picnic_reserved) {
            return;
        }

        $taken = (int) SchoolBooking::query()
            ->whereDate('visit_date', $booking->visit_date->toDateString())
            ->where('picnic_reserved', true)
            ->where('id', '!=', $booking->id)
            ->sum('picnic_seats');
        $capacity = $this->settings->picnicCapacity();

        if ($taken + $booking->picnic_seats > $capacity) {
            throw AdventureParksException::refuse('picnic_capacity_exceeded', 'The picnic area is full on that day.', [
                'capacity' => $capacity,
                'taken' => $taken,
                'requested' => $booking->picnic_seats,
            ]);
        }
    }

    /**
     * Columns of the booking among the validated data (the workshops and the computed values are handled apart).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'institute_name', 'mechanographic_code', 'ipa_office_code', 'cig_code', 'cup_code', 'split_payment', 'visit_date', 'start_time',
            'end_time', 'picnic_reserved', 'paying_students', 'disabled_students', 'accompanying_teachers', 'customer_contact_id',
        ]));
    }
}
