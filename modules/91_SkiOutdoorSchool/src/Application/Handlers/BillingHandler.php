<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Handlers;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\SkiOutdoorSchool\Domain\Enums\EnrollmentStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\LessonStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\VatRegime;
use Modules\SkiOutdoorSchool\Domain\Exceptions\SkiSchoolException;
use Modules\SkiOutdoorSchool\Domain\Services\PackagePricingService;
use Modules\SkiOutdoorSchool\Models\CourseEnrollment;
use Modules\SkiOutdoorSchool\Models\PackageItem;
use Modules\SkiOutdoorSchool\Models\SkiLesson;

/**
 * Invoices a lesson or a course enrolment with its package. The instruction is exempt from VAT (Italy Art. 10 n. 20 DPR 633/72, nature N4;
 * Switzerland Art. 21 para. 2 no. 11 LIVA). Because an Italian document carries a single VAT nature, the taxable add-ons (rental, skipass)
 * go on a second invoice there; a Swiss invoice may mix exempt and taxed lines.
 *
 * @phpstan-import-type Line from PackagePricingService
 */
final class BillingHandler
{
    public function __construct(
        private readonly InvoicingServiceInterface $invoicing,
        private readonly PackagePricingService $pricing,
    ) {}

    /**
     * @return array{invoice_id: string, addons_invoice_id: string|null, totals: array{net_cents: int, vat_cents: int, gross_cents: int}}
     *
     * @throws SkiSchoolException lesson_not_found, lesson_not_billable, lesson_invoiced, customer_contact_required
     */
    public function invoiceLesson(string $lessonId, ?string $contactId, ?string $userId): array
    {
        return DB::transaction(function () use ($lessonId, $contactId, $userId): array {
            $lesson = SkiLesson::query()->with('packageItems')->whereKey($lessonId)->lockForUpdate()->first() ?? throw SkiSchoolException::because('lesson_not_found', [], 404);

            if (! in_array($lesson->status, [LessonStatus::CONFIRMED, LessonStatus::COMPLETED], true)) {
                throw SkiSchoolException::because('lesson_not_billable', ['status' => $lesson->status->value]);
            }

            if ($lesson->invoice_id !== null) {
                throw SkiSchoolException::because('lesson_invoiced');
            }

            $contact = $this->contact($contactId ?? $lesson->customer_contact_id);
            $description = __('ski_school.invoice.lesson_line', ['reference' => $lesson->reference, 'date' => $lesson->lesson_date->toDateString(), 'time' => $lesson->start_time, 'minutes' => $lesson->duration_minutes]);
            $result = $this->emit($contact, (string) $lesson->tenant_id, $description, (float) $lesson->price_net, $lesson->vat_regime, $lesson->packageItems, $lesson->reference, $userId);
            $lesson->update(['customer_contact_id' => (string) $contact->id, 'invoice_id' => $result['invoice_id'], 'addons_invoice_id' => $result['addons_invoice_id']]);

            return $result;
        });
    }

    /**
     * @return array{invoice_id: string, addons_invoice_id: string|null, totals: array{net_cents: int, vat_cents: int, gross_cents: int}}
     *
     * @throws SkiSchoolException enrollment_not_found, enrollment_not_billable, enrollment_invoiced, customer_contact_required
     */
    public function invoiceEnrollment(string $enrollmentId, ?string $contactId, ?string $userId): array
    {
        return DB::transaction(function () use ($enrollmentId, $contactId, $userId): array {
            $enrollment = CourseEnrollment::query()->with(['packageItems', 'course'])->whereKey($enrollmentId)->lockForUpdate()->first() ?? throw SkiSchoolException::because('enrollment_not_found', [], 404);

            if ($enrollment->status !== EnrollmentStatus::ENROLLED) {
                throw SkiSchoolException::because('enrollment_not_billable', ['status' => $enrollment->status->value]);
            }

            if ($enrollment->invoice_id !== null) {
                throw SkiSchoolException::because('enrollment_invoiced');
            }

            $contact = $this->contact($contactId ?? $enrollment->contact_id);
            $course = $enrollment->course;
            $description = __('ski_school.invoice.course_line', ['code' => $course->code, 'student' => $enrollment->student_name, 'from' => $course->week_start->toDateString(), 'to' => $course->end_date->toDateString()]);
            $result = $this->emit($contact, (string) $enrollment->tenant_id, $description, (float) $enrollment->price_net, $course->vat_regime, $enrollment->packageItems, (string) $enrollment->booking_reference, $userId);
            $enrollment->update(['contact_id' => (string) $contact->id, 'invoice_id' => $result['invoice_id'], 'addons_invoice_id' => $result['addons_invoice_id']]);

            return $result;
        });
    }

    /**
     * @param  iterable<int, PackageItem>  $items
     * @return array{invoice_id: string, addons_invoice_id: string|null, totals: array{net_cents: int, vat_cents: int, gross_cents: int}}
     */
    private function emit(Contact $contact, string $tenantId, string $description, float $net, VatRegime $regime, iterable $items, string $reference, ?string $userId): array
    {
        $jurisdiction = $regime->jurisdiction();
        $rows = [];

        foreach ($items as $item) {
            if ($item->status === PackageItemStatus::CANCELLED) {
                continue;
            }

            $rows[] = [
                'item_type' => $item->item_type->value,
                'description' => $item->description ?? __('ski_school.package.'.strtolower($item->item_type->value)),
                'quantity' => (float) $item->quantity,
                'days' => $item->days,
                'unit_price_net' => (float) $item->unit_price_net,
                'vat_rate' => (float) $item->vat_rate,
            ];
        }

        $lines = $this->pricing->lines($description, $net, $regime, $rows);
        $totals = $this->pricing->totals($lines);
        $groups = $jurisdiction === 'IT'
            ? array_values(array_filter([
                'exempt' => array_values(array_filter($lines, static fn (array $line): bool => $line['kind'] === 'instruction' && $line['tax_rate'] === 0.0)),
                'taxed' => array_values(array_filter($lines, static fn (array $line): bool => ! ($line['kind'] === 'instruction' && $line['tax_rate'] === 0.0))),
            ], static fn (array $group): bool => $group !== []))
            : [$lines];

        $ids = [];

        foreach ($groups as $group) {
            $nature = $group[0]['nature'] ?? null;
            $data = [
                'tenant_id' => $tenantId,
                'contact_id' => (string) $contact->id,
                'invoice_type' => 'standard',
                'document_type' => 'TD01',
                'date' => now()->toDateString(),
                'currency' => $jurisdiction === 'CH' ? 'CHF' : 'EUR',
                'jurisdiction' => $jurisdiction,
                'notes' => trim($reference.' '.($group[0]['legal_reference'] ?? '')),
                'created_by' => $userId,
            ] + ($nature !== null ? ['vat_nature' => $nature] : []);

            $ids[] = (string) $this->invoicing->createInvoice($data, array_map(static fn (array $line): array => [
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => $line['tax_rate'],
            ], $group))->id;
        }

        return ['invoice_id' => $ids[0], 'addons_invoice_id' => $ids[1] ?? null, 'totals' => $totals];
    }

    private function contact(?string $contactId): Contact
    {
        if ($contactId === null) {
            throw SkiSchoolException::because('customer_contact_required');
        }

        return Contact::query()->find($contactId) ?? throw SkiSchoolException::because('customer_contact_required');
    }
}
