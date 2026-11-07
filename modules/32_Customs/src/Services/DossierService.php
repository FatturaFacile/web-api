<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceService;
use App\Services\Sales\DocumentNumberingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Customs\Enums\AssessmentDocumentType;
use Modules\Customs\Enums\ChargeCategory;
use Modules\Customs\Enums\ChargeKind;
use Modules\Customs\Enums\DossierStatus;
use Modules\Customs\Enums\ShipmentRequestStatus;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsConsolidation;
use Modules\Customs\Models\CustomsDossier;
use Modules\Customs\Models\CustomsDossierCharge;
use Modules\Customs\Models\CustomsDossierDelivery;
use Modules\Customs\Models\CustomsDossierDocument;
use Modules\Customs\Models\CustomsShipmentRequest;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Forwarding dossiers: the charges of a shipment, what it earns, how it is billed and whether the duty and VAT billed
 * match the assessment. All amounts of a dossier are in its currency.
 */
final class DossierService
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function open(array $data, ?string $actorId): CustomsDossier
    {
        return CustomsDossier::query()->create([
            ...$data,
            'dossier_number' => $this->numbering->next('dossier')['number'],
            'status' => DossierStatus::Open,
            'created_by' => $actorId,
        ]);
    }

    /**
     * Turns a request of the customer portal into a dossier.
     */
    public function acceptRequest(CustomsShipmentRequest $request, string $currency, ?string $actorId): CustomsDossier
    {
        if ($request->status !== ShipmentRequestStatus::Pending) {
            throw new CustomsException('The shipment request has already been answered.');
        }

        return DB::transaction(function () use ($request, $currency, $actorId): CustomsDossier {
            $dossier = $this->open([
                'customer_contact_id' => $request->contact_id,
                'service_type' => $request->service_type,
                'direction' => $request->direction,
                'origin_country' => $request->origin_country,
                'destination_country' => $request->destination_country,
                'gross_weight_kg' => $request->gross_weight_kg,
                'volume_m3' => $request->volume_m3,
                'packages' => $request->packages,
                'currency' => $currency,
                'notes' => $request->notes,
            ], $actorId);

            $request->update(['status' => ShipmentRequestStatus::Accepted, 'dossier_id' => $dossier->id]);

            return $dossier;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addCharge(CustomsDossier $dossier, array $data): CustomsDossierCharge
    {
        return $dossier->charges()->create([...$data, 'currency' => $dossier->currency]);
    }

    /**
     * Brings duty and import VAT of the decisions of the dossier in as costs. A corrected decision updates the charge
     * of its document instead of adding to it. Running it twice changes nothing.
     *
     * @return Collection<int, CustomsDossierCharge>
     */
    public function importAssessments(CustomsDossier $dossier): Collection
    {
        $this->assertChf($dossier);

        $latest = $this->decisionsOf($dossier)
            ->groupBy(static fn (CustomsAssessmentDecision $decision): string => $decision->mrn.'|'.$decision->document_type->value)
            ->map(static fn ($versions): CustomsAssessmentDecision => $versions->sortBy('version')->last());

        DB::transaction(function () use ($dossier, $latest): void {
            foreach ($latest as $decision) {
                if ($decision->amount_not_charged) {
                    continue;
                }

                $earlier = CustomsAssessmentDecision::query()->where('mrn', $decision->mrn)->where('document_type', $decision->document_type->value)->pluck('id');
                $charge = $dossier->charges()->where('kind', ChargeKind::Cost->value)->whereIn('assessment_decision_id', $earlier)->first();
                $values = [
                    'kind' => ChargeKind::Cost,
                    'category' => $this->categoryOf($decision),
                    'is_direct' => true,
                    'description' => "{$decision->document_type->value} {$decision->mrn} v{$decision->version}",
                    'amount' => $this->signedAmount($decision),
                    'currency' => $dossier->currency,
                    'assessment_decision_id' => $decision->id,
                ];

                $charge === null ? $dossier->charges()->create($values) : $charge->update($values);
            }
        });

        return $dossier->charges()->whereNotNull('assessment_decision_id')->get();
    }

    /**
     * Revenue, direct and indirect costs and the margin of a dossier.
     *
     * @return array<string, string>
     */
    public function profitability(CustomsDossier $dossier): array
    {
        $charges = $dossier->charges()->get();
        $sum = static fn (callable $filter): string => $charges->filter($filter)->reduce(
            static fn (string $total, CustomsDossierCharge $charge): string => Decimal::add($total, (string) $charge->amount),
            '0',
        );

        $revenue = $sum(static fn (CustomsDossierCharge $charge): bool => $charge->kind === ChargeKind::Revenue);
        $direct = $sum(static fn (CustomsDossierCharge $charge): bool => $charge->kind === ChargeKind::Cost && $charge->is_direct);
        $indirect = $sum(static fn (CustomsDossierCharge $charge): bool => $charge->kind === ChargeKind::Cost && ! $charge->is_direct);
        $contribution = Decimal::sub($revenue, $direct);
        $result = Decimal::sub($contribution, $indirect);

        return [
            'currency' => $dossier->currency,
            'revenue' => Decimal::round2($revenue),
            'direct_costs' => Decimal::round2($direct),
            'indirect_costs' => Decimal::round2($indirect),
            'contribution_margin' => Decimal::round2($contribution),
            'result' => Decimal::round2($result),
            'margin_percent' => Decimal::isZero($revenue) ? '0.00' : Decimal::round2(Decimal::div(Decimal::mul($result, '100'), $revenue)),
        ];
    }

    /**
     * Whether what is billed to the customer as duty and VAT is what customs assessed.
     *
     * @return array<string, mixed>
     */
    public function assessmentBillingCheck(CustomsDossier $dossier): array
    {
        $charges = $dossier->charges()->get();
        $decisions = $this->decisionsOf($dossier)
            ->groupBy(static fn (CustomsAssessmentDecision $decision): string => $decision->mrn.'|'.$decision->document_type->value)
            ->map(static fn ($versions): CustomsAssessmentDecision => $versions->sortBy('version')->last())
            ->reject(static fn (CustomsAssessmentDecision $decision): bool => $decision->amount_not_charged);

        $lines = [];

        foreach ([ChargeCategory::Duty, ChargeCategory::ImportVat] as $category) {
            $assessed = $decisions
                ->filter(fn (CustomsAssessmentDecision $decision): bool => $this->categoryOf($decision) === $category)
                ->reduce(fn (string $total, CustomsAssessmentDecision $decision): string => Decimal::add($total, $this->signedAmount($decision)), '0');
            $billed = $charges
                ->filter(static fn (CustomsDossierCharge $charge): bool => $charge->kind === ChargeKind::Revenue && $charge->category === $category)
                ->reduce(static fn (string $total, CustomsDossierCharge $charge): string => Decimal::add($total, (string) $charge->amount), '0');

            $lines[] = [
                'category' => $category->value,
                'assessed' => Decimal::round2($assessed),
                'billed' => Decimal::round2($billed),
                'difference' => Decimal::round2(Decimal::sub($billed, $assessed)),
                'matches' => Decimal::compare($billed, $assessed) === 0,
            ];
        }

        return ['matches' => ! in_array(false, array_column($lines, 'matches'), true), 'lines' => $lines];
    }

    /**
     * Issues the revenue charges not yet billed as one invoice, through the invoicing flow of the application.
     *
     * @return array<string, mixed>
     */
    public function invoice(CustomsDossier $dossier, string $date): array
    {
        return DB::transaction(function () use ($dossier, $date): array {
            $charges = $dossier->charges()->where('kind', ChargeKind::Revenue->value)->whereNull('invoice_id')->lockForUpdate()->get();

            if ($charges->isEmpty()) {
                throw new CustomsException('The dossier has no revenue left to bill.');
            }

            if ($charges->contains(static fn (CustomsDossierCharge $charge): bool => $charge->tax_rate === null)) {
                throw new CustomsException('Every revenue line needs its tax rate before it is billed.');
            }

            $invoice = $this->invoices->createInvoice([
                'contact_id' => $dossier->customer_contact_id,
                'invoice_type' => 'standard',
                'date' => $date,
                'currency' => $dossier->currency,
                'notes' => "Dossier {$dossier->dossier_number}",
                'items' => $charges->map(static fn (CustomsDossierCharge $charge): array => [
                    'description' => $charge->description,
                    'quantity' => 1,
                    'unit_price' => $charge->amount,
                    'tax_rate' => $charge->tax_rate,
                ])->all(),
            ]);

            $dossier->charges()->whereKey($charges->modelKeys())->update(['invoice_id' => $invoice->id]);
            $dossier->update(['status' => DossierStatus::Invoiced]);

            return ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'total_gross' => $invoice->total_gross];
        });
    }

    /**
     * The dossiers of a customer in a month, for the monthly summary.
     *
     * @return array<string, mixed>
     */
    public function monthlySummary(Contact $customer, int $year, int $month): array
    {
        $from = CarbonImmutable::create($year, $month, 1);
        $dossiers = CustomsDossier::query()
            ->where('customer_contact_id', $customer->id)
            ->whereBetween('created_at', [$from->startOfMonth(), $from->endOfMonth()])
            ->orderBy('dossier_number')
            ->get();

        $rows = [];
        $total = '0';

        foreach ($dossiers as $dossier) {
            $profit = $this->profitability($dossier);
            $total = Decimal::add($total, $profit['revenue']);
            $rows[] = [
                'dossier_number' => $dossier->dossier_number,
                'status' => $dossier->status->value,
                'currency' => $dossier->currency,
                'revenue' => $profit['revenue'],
                'direct_costs' => $profit['direct_costs'],
                'result' => $profit['result'],
            ];
        }

        return ['contact_id' => $customer->id, 'year' => $year, 'month' => $month, 'revenue_total' => Decimal::round2($total), 'dossiers' => $rows];
    }

    public function storeDocument(CustomsDossier $dossier, Tenant $tenant, UploadedFile $file, string $kind): CustomsDossierDocument
    {
        $id = (string) Str::uuid7();
        $disk = (string) config('customs.archive_disk');
        $path = $file->storeAs("customs/{$tenant->id}/dossiers/{$dossier->id}", $id.'.'.$file->extension(), $disk);

        if ($path === false) {
            throw new CustomsException('The document cannot be stored.');
        }

        return $dossier->documents()->create([
            'id' => $id,
            'document_kind' => $kind,
            'file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'storage_path' => $path,
            'sha256' => (string) hash_file('sha256', (string) $file->getRealPath()),
        ]);
    }

    /**
     * Adds a part of a split delivery. The parts cannot exceed the shipment.
     *
     * @param  array<string, mixed>  $data
     */
    public function addDelivery(CustomsDossier $dossier, array $data): CustomsDossierDelivery
    {
        $delivered = (int) $dossier->deliveries()->sum('packages');

        if ($dossier->packages > 0 && $delivered + (int) ($data['packages'] ?? 0) > $dossier->packages) {
            throw new CustomsException('The deliveries exceed the packages of the dossier.');
        }

        $delivery = $dossier->deliveries()->create($data);

        if ($dossier->packages > 0 && $delivered + (int) ($data['packages'] ?? 0) === $dossier->packages) {
            $dossier->update(['status' => DossierStatus::Delivered]);
        }

        return $delivery;
    }

    /**
     * Shares the freight of a groupage among its dossiers by weight. The last dossier takes the rounding, so the
     * shares always add up to the freight.
     *
     * @return list<array{dossier_id: string, share: string}>
     */
    public function allocateConsolidation(CustomsConsolidation $consolidation): array
    {
        return DB::transaction(function () use ($consolidation): array {
            $consolidation = CustomsConsolidation::query()->lockForUpdate()->findOrFail($consolidation->id);

            if ($consolidation->allocated_at !== null) {
                throw new CustomsException('The freight of this consolidation has already been shared out.');
            }

            return $this->allocateLockedConsolidation($consolidation);
        });
    }

    /**
     * Puts a dossier on a consolidation. Both the target and any consolidation the dossier leaves are locked and must not
     * be shared out yet: a dossier whose freight share was booked cannot carry it to another groupage.
     */
    public function attachToConsolidation(CustomsConsolidation $consolidation, CustomsDossier $dossier): CustomsDossier
    {
        return DB::transaction(function () use ($consolidation, $dossier): CustomsDossier {
            $dossier = CustomsDossier::query()->lockForUpdate()->findOrFail($dossier->id);
            $ids = array_values(array_unique(array_filter([(string) $consolidation->id, (string) $dossier->consolidation_id])));
            sort($ids);
            $locked = CustomsConsolidation::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            if ($locked->contains(static fn (CustomsConsolidation $held): bool => $held->allocated_at !== null)) {
                throw new CustomsException('The freight of this consolidation has already been shared out.');
            }

            $dossier->update(['consolidation_id' => $consolidation->id]);

            return $dossier;
        });
    }

    /**
     * @return list<array{dossier_id: string, share: string}>
     */
    private function allocateLockedConsolidation(CustomsConsolidation $consolidation): array
    {
        $dossiers = $consolidation->dossiers()->orderBy('dossier_number')->get();
        $weight = $dossiers->reduce(static fn (string $total, CustomsDossier $dossier): string => Decimal::add($total, (string) $dossier->gross_weight_kg), '0');

        if (! Decimal::isPositive($weight)) {
            throw new CustomsException('The dossiers of the consolidation have no weight to share the freight by.');
        }

        if ($dossiers->contains(static fn (CustomsDossier $dossier): bool => $dossier->currency !== $consolidation->currency)) {
            throw new CustomsException('A dossier of the consolidation is in another currency.');
        }

        $freight = (string) $consolidation->freight_cost;
        $remaining = $freight;
        $shares = [];

        foreach ($dossiers->values() as $index => $dossier) {
            $share = $index === $dossiers->count() - 1
                ? $remaining
                : Decimal::round2(Decimal::div(Decimal::mul($freight, (string) $dossier->gross_weight_kg), $weight));
            $remaining = Decimal::sub($remaining, $share);

            $dossier->charges()->create([
                'kind' => ChargeKind::Cost,
                'category' => ChargeCategory::Freight,
                'is_direct' => true,
                'description' => "Groupage {$consolidation->reference}",
                'amount' => Decimal::round2($share),
                'currency' => $consolidation->currency,
                'consolidation_id' => $consolidation->id,
            ]);

            $shares[] = ['dossier_id' => (string) $dossier->id, 'share' => Decimal::round2($share)];
        }

        $consolidation->update(['allocated_at' => now(), 'status' => 'allocated']);

        return $shares;
    }

    private function assertChf(CustomsDossier $dossier): void
    {
        if ($dossier->currency !== 'CHF') {
            throw new CustomsException('Assessment decisions are in CHF: the dossier is not.');
        }
    }

    /**
     * @return Collection<int, CustomsAssessmentDecision>
     */
    private function decisionsOf(CustomsDossier $dossier): Collection
    {
        return CustomsAssessmentDecision::query()
            ->where('direction', 'import')
            ->whereIn('declaration_id', $dossier->declarations()->pluck('id'))
            ->get();
    }

    private function categoryOf(CustomsAssessmentDecision $decision): ChargeCategory
    {
        return in_array($decision->document_type, [AssessmentDocumentType::Vat, AssessmentDocumentType::RefundVat], true)
            ? ChargeCategory::ImportVat
            : ChargeCategory::Duty;
    }

    private function signedAmount(CustomsAssessmentDecision $decision): string
    {
        $amount = Decimal::round2($this->categoryOf($decision) === ChargeCategory::ImportVat ? (string) $decision->vat_amount : (string) $decision->duty_amount);

        return $decision->document_type->isRefund() ? Decimal::round2(Decimal::neg($amount)) : $amount;
    }
}
